<?php

require_once 'vendor/autoload.php';

use PrtrCrawler\PrtrCrawler;

try {
    // Set timezone to Taiwan
    date_default_timezone_set('Asia/Taipei');
    
    $crawler = new PrtrCrawler('data');
    
    echo "=== Crawling penalty data by quarters backwards until no data found ===\n";
    $periodsResult = $crawler->crawlBackwardsByQuarters(
        [
            'County' => '', // Empty for all counties
            'PageSize' => -1 // Get all records
        ],
        3 // Stop after 3 consecutive empty periods
    );
    
    if ($periodsResult['success']) {
        echo "Successfully processed {$periodsResult['periods_processed']} quarters with data!\n";
        echo "Total quarters checked: {$periodsResult['total_periods_checked']}\n";
        
        $totalSavedFiles = 0;
        $totalErrors = 0;
        
        // Process each quarter's results
        foreach ($periodsResult['results'] as $periodIndex => $result) {
            echo "\n=== Quarter " . ($periodIndex + 1) . " Results ===\n";
            echo "CSV files processed: {$result['csv_files_processed']}\n";
            echo "Records saved: {$result['total_records_saved']}\n";
            echo "Errors: {$result['total_errors']}\n";
            
            $totalSavedFiles += $result['total_records_saved'];
            $totalErrors += $result['total_errors'];
        }
        
        echo "\n=== Summary ===\n";
        echo "Total quarters processed: {$periodsResult['periods_processed']}\n";
        echo "Total files saved: {$totalSavedFiles}\n";
        echo "Total errors: {$totalErrors}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}