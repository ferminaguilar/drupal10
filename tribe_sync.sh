#!/bin/bash

# ==============================================================================
# BIA.gov - Tribal Content Synchronizer Wrapper Script
# Automates cache clearing and error tracking for the custom Drush sync tool.
# ==============================================================================

# Ensure the script runs from the Drupal webroot directory (prefer project web/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Prefer the typical Composer-installed webroot layout (web/), fall back to script dir
if [ -d "$SCRIPT_DIR/web" ]; then
    cd "$SCRIPT_DIR/web" || exit 1
else
    cd "$SCRIPT_DIR" || exit 1
fi

# Resolve drush command: prefer system `drush`, then common vendor locations
if command -v drush >/dev/null 2>&1; then
    DRUSH_CMD="drush"
elif [ -x "$SCRIPT_DIR/vendor/bin/drush" ]; then
    DRUSH_CMD="$SCRIPT_DIR/vendor/bin/drush"
elif [ -x "$SCRIPT_DIR/../vendor/bin/drush" ]; then
    DRUSH_CMD="$SCRIPT_DIR/../vendor/bin/drush"
elif [ -x "vendor/bin/drush" ]; then
    DRUSH_CMD="vendor/bin/drush"
else
    echo -e "${RED}✘ Error: Drush not found in PATH or vendor directories.${NC}"
    echo -e "${RED}Install Drush or ensure it's available as vendor/bin/drush.${NC}"
    exit 1
fi

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
if "$DRUSH_CMD" cr; then
    echo -e "${GREEN}✔ Cache rebuild complete successfully.${NC}"
else
    echo -e "${RED}✘ Error: Drush cache rebuild failed. Aborting synchronization.${NC}"
    exit 1
fi

# Step 2: Run the Quadruple Fail-Safe Sync Engine
echo -e "\n${YELLOW}[2/3] Fetching remote ArcGIS Geodatabase and EPA Registry data...${NC}"
START_TIME=$(date +%s)

# Execute the sync and capture output stream
SYNC_OUTPUT=$("$DRUSH_CMD" tribe:sync 2>&1)
SYNC_STATUS=$?

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

# Step 3: Parse and Display Output
if [ $SYNC_STATUS -eq 0 ]; then
    echo -e "${GREEN}[3/3] Synchronization Phase Completed.${NC}\n"
    echo "$SYNC_OUTPUT"
    echo -e "\n${GREEN}====================================================${NC}"
    echo -e "${GREEN}✔ SUCCESS: Synchronization finished in ${DURATION}s.${NC}"
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