<?php

namespace PrtrCrawler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use ZipArchive;

class PrtrCrawler
{
    private Client $httpClient;
    private Logger $logger;
    private string $baseUrl = 'https://prtr.moenv.gov.tw/api/v1/Penalty/PenaltyFile';
    private string $dataDir;

    public function __construct(string $dataDir = 'data')
    {
        // Set timezone to Taiwan
        date_default_timezone_set('Asia/Taipei');
        $this->httpClient = new Client([
            'timeout' => 120,
            'connect_timeout' => 30,
            'read_timeout' => 120,
            'http_errors' => false,
            'headers' => [
                'accept' => 'application/json',
                'accept-language' => 'zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                'cache-control' => 'no-cache',
                'pragma' => 'no-cache',
                'referer' => 'https://prtr.moenv.gov.tw/sanctions.html',
                'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
                'x-requested-with' => 'XMLHttpRequest',
                'connection' => 'keep-alive'
            ],
            'curl' => [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 30,
                CURLOPT_TCP_KEEPINTVL => 10
            ]
        ]);

        $this->logger = new Logger('prtr-crawler');
        $this->logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));
        
        $this->dataDir = $dataDir;
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function crawlPenaltyDataByPeriods(string $startDate, string $endDate, array $additionalParams = []): array
    {
        $periods = $this->generateThreeMonthPeriods($startDate, $endDate);
        $allResults = [];
        
        $this->logger->info("Starting crawl with {count} periods", ['count' => count($periods)]);
        
        foreach ($periods as $index => $period) {
            $this->logger->info("Crawling period {current}/{total}: {start} to {end}", [
                'current' => $index + 1,
                'total' => count($periods),
                'start' => $period['start'],
                'end' => $period['end']
            ]);
            
            $params = array_merge($additionalParams, [
                'StartDate' => $period['start'],
                'EndDate' => $period['end']
            ]);
            
            try {
                $result = $this->crawlPenaltyData($params);
                $allResults[] = $result;
                
                // Add small delay between requests
                sleep(1);
                
            } catch (\Exception $e) {
                $this->logger->error("Failed to crawl period {start} to {end}: {error}", [
                    'start' => $period['start'],
                    'end' => $period['end'],
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }
        
        return [
            'success' => true,
            'periods_processed' => count($allResults),
            'results' => $allResults
        ];
    }

    private function generateThreeMonthPeriods(string $startDate, string $endDate): array
    {
        $periods = [];
        $current = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        
        while ($current <= $end) {
            $periodEnd = clone $current;
            $periodEnd->add(new \DateInterval('P3M'))->sub(new \DateInterval('P1D'));
            
            if ($periodEnd > $end) {
                $periodEnd = clone $end;
            }
            
            $periods[] = [
                'start' => $current->format('Y-m-d'),
                'end' => $periodEnd->format('Y-m-d')
            ];
            
            $current->add(new \DateInterval('P3M'));
        }
        
        return $periods;
    }

    public function crawlPenaltyData(array $params = []): array
    {
        $defaultParams = [
            'UniformNo' => '',
            'FacilityName' => '',
            'County' => '',
            'PenaltyAgencyList' => '',
            'Regulations' => '',
            'Law' => '',
            'StartDate' => '2024-07-23',
            'EndDate' => '2025-07-23',
            'RegistrationNo' => '',
            'PageSize' => -1
        ];

        $queryParams = array_merge($defaultParams, $params);
        
        $this->logger->info('Starting penalty data crawl', $queryParams);

        try {
            $response = $this->httpClient->get($this->baseUrl, [
                'query' => $queryParams
            ]);

            $statusCode = $response->getStatusCode();
            $contentType = $response->getHeader('content-type')[0] ?? '';

            $this->logger->info("Response received", [
                'status_code' => $statusCode,
                'content_type' => $contentType
            ]);

            if ($statusCode !== 200) {
                throw new \Exception("HTTP request failed with status code: {$statusCode}");
            }

            $body = $response->getBody()->getContents();

            // Check if response is a ZIP file
            if (strpos($contentType, 'application/zip') !== false || 
                substr($body, 0, 2) === 'PK') {
                return $this->handleZipResponse($body, $queryParams);
            } else {
                // Handle JSON response
                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Failed to decode JSON response: ' . json_last_error_msg());
                }
                return $data;
            }

        } catch (GuzzleException $e) {
            $this->logger->error('HTTP request failed', ['error' => $e->getMessage()]);
            throw new \Exception('Failed to fetch penalty data: ' . $e->getMessage());
        }
    }

    private function handleZipResponse(string $zipContent, array $params): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        
        // Process ZIP content directly in memory
        $csvData = $this->processZipInMemory($zipContent);
        
        $this->logger->info("ZIP processed in memory", [
            'csv_files_found' => count($csvData),
            'total_records' => array_sum(array_map('count', $csvData))
        ]);

        // Save CSV data directly as JSON files
        $totalSavedFiles = 0;
        $totalErrors = 0;
        
        foreach ($csvData as $fileName => $records) {
            if (!empty($records)) {
                $this->logger->info("Processing CSV data from {file}", ['file' => $fileName]);
                $saveResult = $this->saveAsJsonFiles($records, 'docs/sanctions');
                $totalSavedFiles += count($saveResult['saved_files']);
                $totalErrors += count($saveResult['errors']);
            }
        }

        return [
            'success' => true,
            'csv_files_processed' => count($csvData),
            'total_records_saved' => $totalSavedFiles,
            'total_errors' => $totalErrors,
            'params' => $params,
            'timestamp' => $timestamp,
            'has_data' => $totalSavedFiles > 0
        ];
    }

    private function processZipInMemory(string $zipContent): array
    {
        // Create temporary file for ZIP content
        $tempFile = tempnam(sys_get_temp_dir(), 'prtr_zip_');
        if (file_put_contents($tempFile, $zipContent) === false) {
            throw new \Exception('Failed to create temporary ZIP file');
        }

        $zip = new ZipArchive();
        $result = $zip->open($tempFile);

        if ($result !== TRUE) {
            unlink($tempFile);
            throw new \Exception("Failed to open ZIP file (Error code: {$result})");
        }

        $csvData = [];

        // Process each file in the ZIP
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $fileName = $zip->getNameIndex($i);
            
            // Check if it's a CSV file
            if (strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'csv') {
                $csvContent = $zip->getFromIndex($i);
                
                if ($csvContent !== false) {
                    $records = $this->parseCsvContent($csvContent, $fileName);
                    $csvData[$fileName] = $records;
                    
                    $this->logger->info("Parsed CSV from ZIP", [
                        'file' => $fileName,
                        'records' => count($records)
                    ]);
                }
            }
        }

        $zip->close();
        unlink($tempFile);

        return $csvData;
    }

    private function parseCsvContent(string $csvContent, string $fileName): array
    {
        $lines = explode("\n", $csvContent);
        $data = [];
        $header = null;

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $row = str_getcsv($line);
            
            if ($header === null) {
                $header = $row;
                continue;
            }

            if (count($row) === count($header)) {
                $data[] = array_combine($header, $row);
            } else {
                $this->logger->warning("CSV row mismatch in {file} at line {line}", [
                    'file' => $fileName,
                    'line' => $lineNumber + 1
                ]);
            }
        }

        return $data;
    }

    public function readCsvFile(string $csvPath): array
    {
        if (!file_exists($csvPath)) {
            throw new \Exception("CSV file not found: {$csvPath}");
        }

        $data = [];
        $handle = fopen($csvPath, 'r');
        
        if ($handle === false) {
            throw new \Exception("Failed to open CSV file: {$csvPath}");
        }

        // Read header
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \Exception("Failed to read CSV header: {$csvPath}");
        }

        // Read data rows
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === count($header)) {
                $data[] = array_combine($header, $row);
            }
        }

        fclose($handle);
        
        $this->logger->info("CSV file read", [
            'file' => $csvPath,
            'rows' => count($data),
            'columns' => count($header)
        ]);

        return $data;
    }

    public function saveAsJsonFiles(array $csvData, string $docsPath = 'docs/sanctions'): array
    {
        $savedFiles = [];
        $errors = [];

        foreach ($csvData as $index => $row) {
            try {
                $uniqueId = $this->findUniqueId($row);
                if (!$uniqueId) {
                    $this->logger->warning("No unique ID found in row {$index}", ['row' => $row]);
                    continue;
                }

                $parsedId = $this->parseUniqueId($uniqueId);
                if (!$parsedId) {
                    $this->logger->warning("Failed to parse unique ID: {$uniqueId}");
                    continue;
                }

                $filePath = $this->createJsonFilePath($docsPath, $parsedId);
                $this->ensureDirectoryExists(dirname($filePath));

                $jsonData = array_merge($row, [
                    'created_at' => date('Y-m-d H:i:s', time())
                ]);

                if (file_put_contents($filePath, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false) {
                    $savedFiles[] = $filePath;
                    $this->logger->info("Saved JSON file", [
                        'file' => $filePath,
                        'unique_id' => $uniqueId
                    ]);
                } else {
                    $errors[] = "Failed to save file: {$filePath}";
                }

            } catch (\Exception $e) {
                $errors[] = "Error processing row {$index}: " . $e->getMessage();
                $this->logger->error("Error processing row", [
                    'row_index' => $index,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->logger->info("JSON file saving completed", [
            'saved_files' => count($savedFiles),
            'errors' => count($errors)
        ]);

        return [
            'saved_files' => $savedFiles,
            'errors' => $errors,
            'total_processed' => count($csvData)
        ];
    }

    private function findUniqueId(array $row): ?string
    {
        // Common column names that might contain the unique ID
        $possibleColumns = ['序號', '編號', 'ID', 'id', '案件編號', '處分書字號', '裁處書字號'];
        
        foreach ($possibleColumns as $column) {
            if (isset($row[$column]) && !empty($row[$column])) {
                $value = trim($row[$column]);
                // Check if it matches the pattern: number-number-number
                if (preg_match('/^\d+-\d+-\d+$/', $value)) {
                    return $value;
                }
            }
        }

        // If no column match, search all values for the pattern
        foreach ($row as $value) {
            if (is_string($value) && preg_match('/^\d+-\d+-\d+$/', trim($value))) {
                return trim($value);
            }
        }

        return null;
    }

    private function parseUniqueId(string $uniqueId): ?array
    {
        if (!preg_match('/^(\d+)-(\d+)-(\d+)$/', $uniqueId, $matches)) {
            return null;
        }

        $code1 = $matches[1];
        $taiwanYear = $matches[2];
        $code3 = $matches[3];

        // Convert Taiwan year to Western year (Taiwan year + 1911)
        $westernYear = intval($taiwanYear) + 1911;

        return [
            'original_id' => $uniqueId,
            'code1' => $code1,
            'taiwan_year' => $taiwanYear,
            'western_year' => $westernYear,
            'code3' => $code3
        ];
    }

    private function createJsonFilePath(string $basePath, array $parsedId): string
    {
        return sprintf(
            '%s/%d/%s/%s.json',
            rtrim($basePath, '/'),
            $parsedId['western_year'],
            $parsedId['code1'],
            $parsedId['code3']
        );
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new \Exception("Failed to create directory: {$directory}");
            }
        }
    }


    public function crawlBackwardsByQuarters(array $additionalParams = [], int $maxEmptyPeriods = 3): array
    {
        $allResults = [];
        $currentYear = (int)date('Y');
        $currentQuarter = $this->getCurrentQuarter();
        $emptyPeriodsCount = 0;
        $periodCount = 0;
        
        while ($emptyPeriodsCount < $maxEmptyPeriods) {
            $periodCount++;
            
            // Generate quarter period
            $quarter = $this->generateQuarterPeriod($currentYear, $currentQuarter);
            
            $this->logger->info("Crawling {quarter} {year} (period {count}): {start} to {end}", [
                'quarter' => $quarter['quarter'],
                'year' => $currentYear,
                'count' => $periodCount,
                'start' => $quarter['start'],
                'end' => $quarter['end']
            ]);
            
            $params = array_merge($additionalParams, [
                'StartDate' => $quarter['start'],
                'EndDate' => $quarter['end']
            ]);
            
            try {
                $result = $this->crawlPenaltyData($params);
                
                if (isset($result['has_data']) && $result['has_data']) {
                    $allResults[] = $result;
                    $emptyPeriodsCount = 0; // Reset counter when we find data
                    $this->logger->info("{quarter} {year} has data, continuing...", [
                        'quarter' => $quarter['quarter'],
                        'year' => $currentYear
                    ]);
                } else {
                    $emptyPeriodsCount++;
                    $this->logger->info("{quarter} {year} has no data ({empty}/{max} empty periods)", [
                        'quarter' => $quarter['quarter'],
                        'year' => $currentYear,
                        'empty' => $emptyPeriodsCount,
                        'max' => $maxEmptyPeriods
                    ]);
                }
                
                // Move to previous quarter
                $currentQuarter--;
                if ($currentQuarter < 1) {
                    $currentQuarter = 4;
                    $currentYear--;
                }
                
                // Add small delay between requests
                sleep(1);
                
            } catch (\Exception $e) {
                $this->logger->error("Failed to crawl {quarter} {year}: {error}", [
                    'quarter' => $quarter['quarter'],
                    'year' => $currentYear,
                    'error' => $e->getMessage()
                ]);
                $emptyPeriodsCount++;
                
                // Still move to previous quarter on error
                $currentQuarter--;
                if ($currentQuarter < 1) {
                    $currentQuarter = 4;
                    $currentYear--;
                }
            }
        }
        
        $this->logger->info("Finished crawling quarters. Found {periods} quarters with data", [
            'periods' => count($allResults)
        ]);
        
        return [
            'success' => true,
            'periods_processed' => count($allResults),
            'total_periods_checked' => $periodCount,
            'results' => $allResults
        ];
    }

    private function getCurrentQuarter(): int
    {
        $month = (int)date('n');
        if ($month <= 3) return 1;      // Q1: Jan, Feb, Mar
        if ($month <= 6) return 2;      // Q2: Apr, May, Jun
        if ($month <= 9) return 3;      // Q3: Jul, Aug, Sep
        return 4;                       // Q4: Oct, Nov, Dec
    }

    private function generateQuarterPeriod(int $year, int $quarter): array
    {
        switch ($quarter) {
            case 1: // Q1: January 1 - March 31
                return [
                    'start' => "{$year}-01-01",
                    'end' => "{$year}-03-31",
                    'quarter' => 'Q1',
                    'year' => $year
                ];
            case 2: // Q2: April 1 - June 30
                return [
                    'start' => "{$year}-04-01",
                    'end' => "{$year}-06-30",
                    'quarter' => 'Q2',
                    'year' => $year
                ];
            case 3: // Q3: July 1 - September 30
                return [
                    'start' => "{$year}-07-01",
                    'end' => "{$year}-09-30",
                    'quarter' => 'Q3',
                    'year' => $year
                ];
            case 4: // Q4: October 1 - December 31
                return [
                    'start' => "{$year}-10-01",
                    'end' => "{$year}-12-31",
                    'quarter' => 'Q4',
                    'year' => $year
                ];
            default:
                throw new \InvalidArgumentException("Invalid quarter: {$quarter}");
        }
    }

    public function cleanup(string $path): bool
    {
        if (is_file($path)) {
            return unlink($path);
        }

        if (is_dir($path)) {
            $files = array_diff(scandir($path), ['.', '..']);
            foreach ($files as $file) {
                $this->cleanup($path . DIRECTORY_SEPARATOR . $file);
            }
            return rmdir($path);
        }

        return false;
    }
}