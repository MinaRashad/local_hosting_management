# service_monitor.sh
#!/bin/bash

# Service monitoring script
# Tracks uptime and resource usage of services defined in services.conf

CONFIG_FILE="services.conf"
DATA_DIR=".server_data"
INTERVAL=60  # Seconds between checks

# Create data directory if it doesn't exist
mkdir -p "$DATA_DIR"

# Function to log service status
log_service_status() {
    local name="$1"
    local port="$2"
    local timestamp=$(date +"%Y-%m-%d %H:%M:%S")
    local status_file="$DATA_DIR/${name}_status.log"
    local usage_file="$DATA_DIR/${name}_usage.log"
    
    # Check if service is running
    if ss -tuln | grep -q ":$port"; then
        status="UP"
        
        # Get PID of process using this port
        local pid=$(lsof -i:$port -t 2>/dev/null | head -1)
        
        if [[ -n "$pid" ]]; then
            # Get CPU and memory usage
            local cpu=$(ps -p $pid -o %cpu= 2>/dev/null || echo "0.0")
            local mem=$(ps -p $pid -o %mem= 2>/dev/null || echo "0.0")
            
            # Log usage data
            echo "$timestamp,$status,$cpu,$mem" >> "$usage_file"
        else
            echo "$timestamp,$status,0.0,0.0" >> "$usage_file"
        fi
    else
        status="DOWN"
        echo "$timestamp,$status,0.0,0.0" >> "$usage_file"
    fi
    
    # Log status with timestamp
    echo "$timestamp,$status" >> "$status_file"
}

# Main monitoring loop
monitor_services() {
    echo "Starting service monitoring..."
    echo "Logs will be saved to $DATA_DIR/"
    
    while true; do
        if [[ -f "$CONFIG_FILE" ]]; then
            while IFS='|' read -r name command port; do
                if [[ -n "$name" && -n "$port" ]]; then
                    log_service_status "$name" "$port"
                fi
            done < "$CONFIG_FILE"
        fi
        
        sleep $INTERVAL
    done
}

# Start monitoring
monitor_services
