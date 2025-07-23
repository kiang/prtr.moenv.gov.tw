<?php

require_once 'vendor/autoload.php';

use PrtrCrawler\PrtrCrawler;

try {
    // Set timezone to Taiwan
    date_default_timezone_set('Asia/Taipei');
    
    // Start daily update
    echo "=== PRTR Daily Update - " . date('Y-m-d H:i:s') . " ===\n";
    
    $crawler = new PrtrCrawler('data');
    
    // Crawl recent 3 months data
    $result = $crawler->crawlRecentThreeMonths([
        'County' => '', // Empty for all counties
        'PageSize' => -1 // Get all records
    ]);
    
    if ($result['success']) {
        echo "Daily update completed successfully!\n";
        echo "Period: {$result['period']['start']} to {$result['period']['end']}\n";
        
        $crawlResult = $result['result'];
        
        if (isset($crawlResult['has_data']) && $crawlResult['has_data']) {
            echo "Data found and processed:\n";
            echo "- CSV files processed: {$crawlResult['csv_files_processed']}\n";
            echo "- Records saved: {$crawlResult['total_records_saved']}\n";
            echo "- Errors: {$crawlResult['total_errors']}\n";
            
            if ($crawlResult['total_errors'] > 0) {
                echo "\nWarning: Some records had errors during processing.\n";
            }
            
            if ($crawlResult['total_records_saved'] > 0) {
                echo "\nSuccess: {$crawlResult['total_records_saved']} new records added to docs/sanctions/\n";
            } else {
                echo "\nInfo: No new records to save (possibly duplicates or empty data).\n";
            }
        } else {
            echo "No new data found in the recent 3 months period.\n";
        }
        
        // Display current time for cron job verification
        echo "\nDaily update finished at: " . date('Y-m-d H:i:s') . "\n";
        
    } else {
        echo "Daily update failed!\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "Error during daily update: " . $e->getMessage() . "\n";
    echo "Time: " . date('Y-m-d H:i:s') . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}