# PRTR Taiwan Environmental Data Crawler

A PHP crawler for retrieving penalty data from Taiwan's Pollutant Release and Transfer Register (PRTR) system.

## Features

- Uses Guzzle HTTP client for reliable API requests
- Automatic ZIP file handling and extraction
- CSV data parsing and processing
- Comprehensive logging with Monolog
- Error handling and retry mechanisms
- Clean data directory management

## Installation

1. Install dependencies via Composer:
```bash
composer install
```

## Usage

### Basic Usage

```php
<?php
require_once 'vendor/autoload.php';

use PrtrCrawler\PrtrCrawler;

$crawler = new PrtrCrawler('data');

// Get penalty data for date range
$result = $crawler->crawlPenaltyData([
    'StartDate' => '2024-07-23',
    'EndDate' => '2025-07-23',
    'County' => '',        // Empty for all counties
    'PageSize' => -1       // Get all records
]);

// Process CSV files
if (isset($result['csv_files'])) {
    foreach ($result['csv_files'] as $csvFile) {
        $data = $crawler->readCsvFile($csvFile);
        // Process your data here
    }
}
```

### Advanced Usage

```php
// Search with specific parameters
$result = $crawler->crawlPenaltyData([
    'UniformNo' => '',
    'FacilityName' => '',
    'County' => '台南市',
    'PenaltyAgencyList' => '',
    'Regulations' => '',
    'Law' => '',
    'StartDate' => '2024-01-01',
    'EndDate' => '2024-12-31',
    'RegistrationNo' => '',
    'PageSize' => -1
]);
```

## API Parameters

- `UniformNo`: Company uniform number
- `FacilityName`: Facility name
- `County`: County/city name
- `PenaltyAgencyList`: Penalty agency list
- `Regulations`: Regulations
- `Law`: Law reference
- `StartDate`: Start date (YYYY-MM-DD)
- `EndDate`: End date (YYYY-MM-DD)
- `RegistrationNo`: Registration number
- `PageSize`: Number of records (-1 for all)

## Data Structure

The crawler returns:
- `success`: Boolean indicating success
- `zip_file`: Path to downloaded ZIP file
- `extract_path`: Path to extracted files
- `csv_files`: Array of CSV file paths
- `params`: Original query parameters
- `timestamp`: Download timestamp

## Error Handling

The crawler includes comprehensive error handling for:
- HTTP request failures
- ZIP file extraction errors
- CSV parsing issues
- File system operations

## Logging

All operations are logged using Monolog. Logs include:
- Request parameters
- Response details
- File operations
- Error messages

## Cleanup

Clean up temporary files:
```php
$crawler->cleanup($result['extract_path']);
$crawler->cleanup($result['zip_file']);
```

## Requirements

- PHP 8.0+
- Guzzle HTTP 7.0+
- Monolog 3.0+
- ZIP extension