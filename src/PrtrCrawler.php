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
    private string $baseUrl = 'https://prtr.moenv.gov.tw/api/v1/Penalty';
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
            'PageNumber' => 1,
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

            // Handle JSON response
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to decode JSON response: ' . json_last_error_msg());
            }

            return $this->handleJsonResponse($data, $queryParams);

        } catch (GuzzleException $e) {
            $this->logger->error('HTTP request failed', ['error' => $e->getMessage()]);
            throw new \Exception('Failed to fetch penalty data: ' . $e->getMessage());
        }
    }

    private function handleJsonResponse(array $data, array $params): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        
        // Extract records from JSON response
        $records = $data['Result']['Data'] ?? $data['data'] ?? $data;
        
        if (!is_array($records)) {
            $records = [];
        }

        $this->logger->info("JSON response processed", [
            'total_records' => count($records)
        ]);

        // Save JSON data directly as JSON files
        $totalSavedFiles = 0;
        $totalErrors = 0;
        
        if (!empty($records)) {
            $saveResult = $this->saveAsJsonFiles($records, 'docs/sanctions');
            $totalSavedFiles = count($saveResult['saved_files']);
            $totalErrors = count($saveResult['errors']);
        }

        return [
            'success' => true,
            'json_response_processed' => true,
            'total_records_saved' => $totalSavedFiles,
            'total_errors' => $totalErrors,
            'params' => $params,
            'timestamp' => $timestamp,
            'has_data' => $totalSavedFiles > 0
        ];
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

                $parsedId = $this->parseUniqueId($uniqueId, $row);
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
        // For new JSON API response structure, create unique ID from COUNTY + DOCUMENTNO
        if (isset($row['COUNTY']) && isset($row['DOCUMENTNO'])) {
            $county = trim($row['COUNTY']);
            $documentNo = trim($row['DOCUMENTNO']);
            
            if (!empty($county) && !empty($documentNo)) {
                return $county . '_' . $documentNo;
            }
        }
        
        // Fallback: try old CSV column names for backward compatibility
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

    private function parseUniqueId(string $uniqueId, array $row = []): ?array
    {
        // Handle new format: COUNTY_DOCUMENTNO (e.g., "高雄市_21-114-070054")
        if (strpos($uniqueId, '_') !== false) {
            list($county, $documentNo) = explode('_', $uniqueId, 2);
            
            // Parse DOCUMENTNO for codes (format: code1-year-code3)
            if (preg_match('/^(\d+)-(\d+)-(\d+)$/', $documentNo, $matches)) {
                $code1 = $matches[1];
                $taiwanYear = $matches[2];
                $code3 = $matches[3];
                
                // Try to get the actual year from the punishment date
                $actualYear = $this->extractYearFromRow($row);
                
                // Use actual year if available, otherwise fall back to Taiwan year conversion
                $westernYear = $actualYear ?: (intval($taiwanYear) + 1911);
                
                return [
                    'original_id' => $uniqueId,
                    'county' => $county,
                    'document_no' => $documentNo,
                    'code1' => $code1,
                    'taiwan_year' => $taiwanYear,
                    'western_year' => $westernYear,
                    'code3' => $code3,
                    'actual_year' => $actualYear
                ];
            }
        }
        
        // Handle old format: direct DOCUMENTNO (format: code1-year-code3)
        if (preg_match('/^(\d+)-(\d+)-(\d+)$/', $uniqueId, $matches)) {
            $code1 = $matches[1];
            $taiwanYear = $matches[2];
            $code3 = $matches[3];

            // Try to get the actual year from the punishment date
            $actualYear = $this->extractYearFromRow($row);
            
            // Use actual year if available, otherwise fall back to Taiwan year conversion
            $westernYear = $actualYear ?: (intval($taiwanYear) + 1911);

            return [
                'original_id' => $uniqueId,
                'code1' => $code1,
                'taiwan_year' => $taiwanYear,
                'western_year' => $westernYear,
                'code3' => $code3,
                'actual_year' => $actualYear
            ];
        }

        return null;
    }

    private function createJsonFilePath(string $basePath, array $parsedId): string
    {
        // Extract county from parsed ID if available
        $county = $parsedId['county'] ?? '';
        
        if (empty($county)) {
            // Fallback to old path structure if county is not available
            return sprintf(
                '%s/%d/%s/%s.json',
                rtrim($basePath, '/'),
                $parsedId['western_year'],
                $parsedId['code1'],
                $parsedId['code3']
            );
        }
        
        // Clean county name for filesystem (remove special characters)
        $county = preg_replace('/[^\p{L}\p{N}]+/u', '_', $county);
        $county = trim($county, '_');
        
        return sprintf(
            '%s/%s/%d/%s/%s.json',
            rtrim($basePath, '/'),
            $county,
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

    public function crawlRecentThreeMonths(array $additionalParams = []): array
    {
        // Calculate 3-month period ending today
        $endDate = new \DateTime();
        $startDate = clone $endDate;
        $startDate->sub(new \DateInterval('P3M'));

        $this->logger->info("Crawling recent 3 months: {start} to {end}", [
            'start' => $startDate->format('Y-m-d'),
            'end' => $endDate->format('Y-m-d')
        ]);

        $params = array_merge($additionalParams, [
            'StartDate' => $startDate->format('Y-m-d'),
            'EndDate' => $endDate->format('Y-m-d')
        ]);

        try {
            $result = $this->crawlPenaltyData($params);
            
            if (isset($result['has_data']) && $result['has_data']) {
                $this->logger->info("Recent 3 months crawl completed successfully", [
                    'records_saved' => $result['total_records_saved'] ?? 0,
                    'errors' => $result['total_errors'] ?? 0
                ]);
            } else {
                $this->logger->info("No new data found in recent 3 months");
            }

            return [
                'success' => true,
                'period' => [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                    'type' => 'recent_3_months'
                ],
                'result' => $result
            ];

        } catch (\Exception $e) {
            $this->logger->error("Failed to crawl recent 3 months: {error}", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function checkForExistingFile(string $uniqueId, string $docsPath = 'docs/sanctions'): bool
    {
        $parsedId = $this->parseUniqueId($uniqueId);
        if (!$parsedId) {
            return false;
        }

        $filePath = $this->createJsonFilePath($docsPath, $parsedId);
        return file_exists($filePath);
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

    private function extractYearFromRow(array $row): ?int
    {
        // New JSON API field names
        $dateFields = ['PENALTYDATE', 'TRANSGRESSDATE', 'UPDATETIME'];
        
        foreach ($dateFields as $field) {
            if (isset($row[$field]) && !empty($row[$field])) {
                $dateValue = trim($row[$field]);
                
                // Handle different date formats
                // Format: 2025/07/03 or 2025/07/03 21:08
                if (preg_match('/^(\d{4})\//', $dateValue, $matches)) {
                    return (int)$matches[1];
                }
                
                // Format: 2025-07-03 or 2025-07-03 21:08:00
                if (preg_match('/^(\d{4})-/', $dateValue, $matches)) {
                    return (int)$matches[1];
                }
            }
        }
        
        // Fallback: try old CSV field names for backward compatibility
        $oldDateFields = ['裁處時間', '違規時間', '裁處日期', '違規日期'];
        
        foreach ($oldDateFields as $field) {
            if (isset($row[$field]) && !empty($row[$field])) {
                $dateValue = trim($row[$field]);
                
                // Handle Taiwan year format (113/07/03 -> 2024)
                if (preg_match('/^(\d{2,3})\//', $dateValue, $matches)) {
                    $taiwanYear = (int)$matches[1];
                    return $taiwanYear + 1911;
                }
                
                // Handle western year format
                if (preg_match('/^(\d{4})/', $dateValue, $matches)) {
                    return (int)$matches[1];
                }
            }
        }
        
        return null;
    }
}