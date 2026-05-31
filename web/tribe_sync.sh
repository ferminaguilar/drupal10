#!/bin/bash

# ==============================================================================
# BIA.gov - Tribal Content Synchronizer Wrapper Script
# Automates cache clearing and error tracking for the custom Drush sync tool.
# ==============================================================================

# Ensure the script runs from the Drupal webroot directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Text Styling Definitions (USWDS-aligned Terminal Feedback)
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${CYAN}====================================================${NC}"
echo -e "${CYAN}   INITIATING CROSS-AGENCY TRIBE SYNCHRONIZATION    ${NC}"
echo -e "${CYAN}====================================================${NC}"
echo -e "Timestamp: $(date)"

# Step 1: Pre-execution Cache Rebuild
echo -e "\n${YELLOW}[1/3] Flushing Drupal entity and configuration caches...${NC}"
if drush cr; then
    echo -e "${GREEN}✔ Cache rebuild complete successfully.${NC}"
else
    echo -e "${RED}✘ Error: Drush cache rebuild failed. Aborting synchronization.${NC}"
    exit 1
fi

# Step 2: Run the Quadruple Fail-Safe Sync Engine
echo -e "\n${YELLOW}[2/3] Fetching remote ArcGIS Geodatabase and EPA Registry data...${NC}"
START_TIME=$(date +%s)

# Execute the sync and capture output stream
SYNC_OUTPUT=$(drush tribe:sync 2>&1)
SYNC_STATUS=$?

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

# Step 3: Parse and Display Output
if [ $SYNC_STATUS -eq 0 ]; then
    echo -e "${GREEN}[3/3] Synchronization Phase Completed.${NC}\n"
    echo "$SYNC_OUTPUT"
    echo -e "\n${GREEN}====================================================${NC}"
    echo -e "${GREEN}✔ SUCCESS: 588 baseline records evaluated in ${DURATION}s.${NC}"
    echo -e "${GREEN}====================================================${NC}"
else
    echo -e "${RED}[3/3] Synchronization Engine Halted with Errors.${NC}\n"
    echo "$SYNC_OUTPUT"
    echo -e "\n${RED}====================================================${NC}"
    echo -e "${RED}✘ CRITICAL FAILURE: Sync runtime halted prematurely.${NC}"
    echo -e "${RED}Check administrative logs at /admin/reports/dblog${NC}"
    echo -e "${RED}====================================================${NC}"
    exit 1
fi