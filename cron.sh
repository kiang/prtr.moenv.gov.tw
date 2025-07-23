#!/bin/bash

# PRTR Daily Update Cron Wrapper Script
# This script provides a robust wrapper for daily_update.php execution

# Set script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR" || exit 1

# Set timezone
export TZ="Asia/Taipei"

# Create logs directory if it doesn't exist
mkdir -p logs

# Log file with date
LOG_FILE="logs/daily_update_$(date +%Y%m%d).log"

# Function to log with timestamp
log_with_timestamp() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Start logging
log_with_timestamp "=== PRTR Daily Update Started ==="
log_with_timestamp "Script directory: $SCRIPT_DIR"
log_with_timestamp "Log file: $LOG_FILE"

# Check if composer dependencies are installed
if [ ! -d "vendor" ]; then
    log_with_timestamp "ERROR: Composer dependencies not found. Running composer install..."
    composer install >> "$LOG_FILE" 2>&1
    if [ $? -ne 0 ]; then
        log_with_timestamp "ERROR: Composer install failed"
        exit 1
    fi
fi

# Check if PHP is available
if ! command -v php &> /dev/null; then
    log_with_timestamp "ERROR: PHP command not found"
    exit 1
fi

# Check if daily_update.php exists
if [ ! -f "daily_update.php" ]; then
    log_with_timestamp "ERROR: daily_update.php not found"
    exit 1
fi

# Git pull to get latest changes
log_with_timestamp "Pulling latest changes from git..."
git pull >> "$LOG_FILE" 2>&1
if [ $? -ne 0 ]; then
    log_with_timestamp "WARNING: Git pull failed, continuing anyway..."
fi

# Execute the PHP script
log_with_timestamp "Executing daily_update.php..."
php daily_update.php >> "$LOG_FILE" 2>&1

# Check exit code
EXIT_CODE=$?
if [ $EXIT_CODE -eq 0 ]; then
    log_with_timestamp "SUCCESS: Daily update completed successfully"
    
    # Check if there are any changes to commit
    if [ -n "$(git status --porcelain)" ]; then
        log_with_timestamp "Changes detected, committing to git..."
        
        # Add all changes
        git add -A >> "$LOG_FILE" 2>&1
        
        # Create commit message with date and stats
        COMMIT_DATE=$(date '+%Y-%m-%d')
        ADDED_FILES=$(git diff --cached --name-only | wc -l)
        
        # Count JSON files added/modified
        JSON_FILES=$(git diff --cached --name-only | grep '\.json$' | wc -l)
        
        COMMIT_MSG="Daily data update ${COMMIT_DATE}

- Added/updated ${JSON_FILES} penalty records
- Total files changed: ${ADDED_FILES}
- Automated update from PRTR API

🤖 Generated with [Claude Code](https://claude.ai/code)

Co-Authored-By: Claude <noreply@anthropic.com>"

        # Commit changes
        git commit -m "$COMMIT_MSG" >> "$LOG_FILE" 2>&1
        if [ $? -eq 0 ]; then
            log_with_timestamp "Git commit successful"
            
            # Push to remote
            log_with_timestamp "Pushing changes to remote repository..."
            git push >> "$LOG_FILE" 2>&1
            if [ $? -eq 0 ]; then
                log_with_timestamp "Git push successful"
            else
                log_with_timestamp "ERROR: Git push failed"
                EXIT_CODE=1
            fi
        else
            log_with_timestamp "ERROR: Git commit failed"
            EXIT_CODE=1
        fi
    else
        log_with_timestamp "No changes to commit"
    fi
else
    log_with_timestamp "ERROR: Daily update failed with exit code $EXIT_CODE"
fi

# Log completion
log_with_timestamp "=== PRTR Daily Update Finished ==="

# Keep only last 30 days of logs
find logs -name "daily_update_*.log" -type f -mtime +30 -delete

# Exit with the same code as PHP script
exit $EXIT_CODE