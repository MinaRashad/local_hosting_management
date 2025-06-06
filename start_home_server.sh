#!/bin/bash
# start_home_server.sh

# Start the home server
echo -e "\033[1;36mStarting home server setup...\033[0m"

# Check if gum is installed
if ! command -v gum &> /dev/null; then
    echo -e "\033[1;31mGum is not installed. Please install it first:\033[0m"
    echo -e "\033[1;33mbrew install gum\033[0m or \033[1;33msudo apt install gum\033[0m"
    exit 1
fi

# Get network IP with `ip address` command
IP=$(ip address | grep 'inet ' | grep 'wlo1' | awk '{print $2}' | awk -F'/' '{print $1}')

# Config file for services
CONFIG_FILE="services.conf"
PID_FILE="/tmp/home_server_pids"

# save current banner
CURRENT_BANNER_TEXT= ''
CURRENT_BANNER_OUTPUT= ''

# Allowed commands - uncomment and modify if you want to restrict commands
# ALLOWED_COMMANDS=("php -S 0.0.0.0:8000" "python3 -m http.server 8000")

# Function to validate command against allowed list
# is_allowed_command() {
#     local cmd="$1"
#     for allowed in "${ALLOWED_COMMANDS[@]}"; do
#         if [[ "$cmd" == "$allowed" ]]; then
#             return 0
#         fi
#     done
#     return 1
# }

# Function to handle cleanup on Ctrl+C
cleanup() {
    # Kill monitoring script if running
    if pgrep -f "service_monitor.sh" > /dev/null; then
        pkill -f "service_monitor.sh"
    fi
    
    # check if ssh is live
    if systemctl is-active --quiet sshd; then
        echo "SSH server is running."
    else
        exit 0
    fi
    ssh_stop=$(gum confirm "Stop SSH server?" --affirmative "Yes" --negative "No")
    if [ $? -eq 0 ]; then
        echo -e "\033[1;31mStopping SSH server...\033[0m"
        sudo systemctl stop ssh
    else
        echo -e "\033[1;32mLeaving SSH server running\033[0m"
    fi
    echo -e "\033[1;36mExiting script.\033[0m"
    exit 0
}

# Function to check if a port is in use
is_port_in_use() {
    local port=$1
    ss -tuln | grep -q ":$port"
}

# Function to load services from config
load_services() {
    services=()
    if [[ ! -f "$CONFIG_FILE" ]]; then
        # Create default config with existing services
        echo -e "\033[1;33mCreating default services configuration...\033[0m"
        
        touch $CONFIG_FILE
    fi
    
    while IFS='|' read -r name command port; do
        services+=("$name|$command|$port")
    done < "$CONFIG_FILE"
}

# Function to save services to config
save_services() {
    > "$CONFIG_FILE"
    for service in "${services[@]}"; do
        echo "$service" >> "$CONFIG_FILE"
    done
}

# Function to add a new service
add_service() {
    clear
    print_banner "ADD SERVICE"
    
    name=$(gum input --placeholder "Enter service name" --prompt "> ")
    command=$(gum input --placeholder "Enter command to run" --prompt "> ")
    port=$(gum input --placeholder "Enter port number" --prompt "> ")
    
    # Validate port is a number
    if ! [[ "$port" =~ ^[0-9]+$ ]]; then
        gum style --foreground 196 "Invalid port number"
        gum input --placeholder "Press Enter to continue..."
        return
    fi
    
    # Warn about arbitrary command execution
    gum style --foreground 196 "WARNING: Running arbitrary commands can be dangerous!"
    gum style --foreground 196 "Make sure you trust this command: $command"
    
    # Uncomment to enable command validation
    # if ! is_allowed_command "$command"; then
    #     gum style --foreground 196 "Command is not allowed"
    #     gum input --placeholder "Press Enter to continue..."
    #     return
    # fi
    
    services+=("$name|$command|$port")
    save_services
    
    gum style --foreground 46 "Service '$name' added successfully"
    gum spin --spinner dot --title "Processing..." -- sleep 1
    gum input --placeholder "Press Enter to continue..."
}

# Function to edit a service
edit_service() {
    local index=$1
    local service="${services[$index]}"
    IFS='|' read -r name command port <<< "$service"
    
    clear
    print_banner "EDIT SERVICE"
    
    gum style --foreground 45 "Editing service: $name"
    gum style --foreground 220 "Current command: $command"
    gum style --foreground 220 "Current port: $port"
    echo ""
    
    new_name=$(gum input --value "$name" --placeholder "Enter new service name (leave empty to keep current)" --prompt "> ")
    new_command=$(gum input --value "$command" --placeholder "Enter new command (leave empty to keep current)" --prompt "> ")
    new_port=$(gum input --value "$port" --placeholder "Enter new port (leave empty to keep current)" --prompt "> ")
    
    # Use current values if new ones are empty
    new_name=${new_name:-$name}
    new_command=${new_command:-$command}
    new_port=${new_port:-$port}
    
    # Validate port is a number
    if ! [[ "$new_port" =~ ^[0-9]+$ ]]; then
        gum style --foreground 196 "Invalid port number, keeping current port"
        new_port=$port
    fi
    
    # Warn about arbitrary command execution
    gum style --foreground 196 "WARNING: Running arbitrary commands can be dangerous!"
    gum style --foreground 196 "Make sure you trust this command: $new_command"
    
    # Uncomment to enable command validation
    # if ! is_allowed_command "$new_command"; then
    #     gum style --foreground 196 "Command is not allowed, keeping current command"
    #     new_command=$command
    # fi
    
    # Update service
    services[$index]="$new_name|$new_command|$new_port"
    save_services
    
    gum style --foreground 46 "Service updated successfully!"
    gum spin --spinner dot --title "Processing..." -- sleep 1
    gum input --placeholder "Press Enter to continue..."
    display_services_menu
}

# Function to delete a service
delete_service() {
    local index=$1
    local service="${services[$index]}"
    IFS='|' read -r name command port <<< "$service"
    
    clear
    print_banner "DELETE SERVICE"
    
    gum style --foreground 196 "Are you sure you want to delete: $name?"
    
    if gum confirm "Type to confirm" --affirmative "Delete" --negative "Cancel"; then
        # Remove the service from the array
        unset services[$index]
        # Reindex array
        services=("${services[@]}")
        save_services
        
        gum style --foreground 46 "Service '$name' deleted successfully"
        gum spin --spinner dot --title "Deleting..." -- sleep 1
    else
        gum style --foreground 220 "Deletion cancelled"
    fi
    
    gum input --placeholder "Press Enter to continue..."
    display_services_menu
}

# Function to print ASCII art banner with animation
print_banner() {
    local text="$1"
    clear

    if [[ "$text" != "$CURRENT_BANNER_TEXT" ]]; then
        CURRENT_BANNER_TEXT="$text"
        local figlet_output
        figlet_output=$(figlet -f small -w 100 -c "$text")


        local colors=("\033[1;31m" "\033[1;33m" "\033[1;32m" "\033[1;36m" "\033[1;34m" "\033[1;35m")
        local color_index=0
        local colored_output=""


        while IFS= read -r line; do
            local colored_line="${colors[$color_index]}$line"
            echo -e "$colored_line"
            colored_output+="$colored_line\n"
            ((color_index = (color_index + 1) % ${#colors[@]}))
            sleep 0.05
        done <<< "$figlet_output"


        CURRENT_BANNER_OUTPUT=$(echo -e "$colored_output")
    else
        echo -e "$CURRENT_BANNER_OUTPUT"
    fi

    echo ""
}

# Function to check service status
check_service_status() {
    local index=$1
    local service="${services[$index]}"
    IFS='|' read -r name command port <<< "$service"
    
    if is_port_in_use "$port"; then
        echo "UP"  # Green
    else
        echo "DOWN"  # Red
    fi
}

# Function to start a service
start_service() {
    local index=$1
    local service="${services[$index]}"
    IFS='|' read -r name command port <<< "$service"
    
    clear
    print_banner "START SERVICE"
    
    if is_port_in_use "$port"; then
        gum style --foreground 220 "Service '$name' is already running"
        gum input --placeholder "Press Enter to continue..."
        return
    fi
    
    gum style --foreground 46 "Starting $name on $IP:$port"
    
    # Uncomment to enable command validation
    # if ! is_allowed_command "$command"; then
    #     gum style --foreground 196 "Command is not allowed"
    #     gum input --placeholder "Press Enter to continue..."
    #     return
    # fi
    
    gum spin --spinner dot --title "Starting service..." -- sleep 1
    
    eval "$command &" >/dev/null 2>&1
    local pid=$!
    echo "$name:$pid" >> "$PID_FILE"
    sleep 1
    
    if is_port_in_use "$port"; then
        gum style --foreground 46 "Service started successfully!"
    else
        gum style --foreground 196 "Service may have failed to start. Check logs."
    fi
    
    gum input --placeholder "Press Enter to continue..."
    display_services_menu
}

# Function to stop a service
stop_service() {
    local index=$1
    local service="${services[$index]}"
    IFS='|' read -r name command port <<< "$service"
    
    clear
    print_banner "STOP SERVICE"
    
    if ! is_port_in_use "$port"; then
        gum style --foreground 220 "Service '$name' is not running"
        gum input --placeholder "Press Enter to continue..."
        return
    fi
    
    gum style --foreground 196 "Stopping $name..."
    
    gum spin --spinner dot --title "Stopping service..." -- sleep 1
    
    # Special handling for SSH
    if [[ "$name" == "SSH" ]]; then
        sudo systemctl stop sshd
        gum input --placeholder "Press Enter to continue..."
        return
    fi
    
    # Find PID from file
    if [[ -f "$PID_FILE" ]]; then
        local pid=$(grep "^$name:" "$PID_FILE" | cut -d':' -f2)
        if [[ -n "$pid" ]]; then
            kill -15 "$pid" 2>/dev/null || kill -9 "$pid" 2>/dev/null
            sed -i "/^$name:/d" "$PID_FILE"
            gum style --foreground 46 "Service stopped using PID: $pid"
        fi
    fi
    
    # Also, try to find and kill process using the port
    local pid=$(lsof -i:$port -t 2>/dev/null)
    if [[ -n "$pid" ]]; then
        gum style --foreground 220 "Found process using port $port: $pid"
        kill -15 $pid 2>/dev/null || kill -9 $pid 2>/dev/null
        gum style --foreground 46 "Process killed"
    else
        gum style --foreground 196 "Could not find process to kill. Try manually stopping the service."
    fi
    
    gum input --placeholder "Press Enter to continue..."
    display_services_menu
}

visualize_service_data() {
    local index=$1
    local service="${services[$index]}"
    IFS='|' read -r name command port <<< "$service"
    
    clear
    print_banner "SERVICE STATS"
    
    local status_file=".server_data/${name}_status.log"
    local usage_file=".server_data/${name}_usage.log"
    
    if [[ ! -f "$status_file" || ! -f "$usage_file" ]]; then
        gum style --foreground 196 "No monitoring data available for $name"
        gum input --placeholder "Press Enter to continue..."
        return
    fi
    
    # Calculate uptime percentage
    local total_records=$(wc -l < "$status_file")
    local up_records=$(grep ",UP$" "$status_file" | wc -l)
    local uptime_pct=0
    
    if [[ $total_records -gt 0 ]]; then
        uptime_pct=$(echo "scale=2; $up_records * 100 / $total_records" | bc)
    fi
    
    # Get latest CPU and memory usage
    local latest_usage=$(tail -n 1 "$usage_file")
    local latest_cpu=$(echo "$latest_usage" | cut -d',' -f3)
    local latest_mem=$(echo "$latest_usage" | cut -d',' -f4)
    
    # Get average CPU and memory usage (only when service was up)
    local avg_cpu=0
    local avg_mem=0
    local up_usage_records=$(grep ",UP," "$usage_file" | wc -l)
    
    if [[ $up_usage_records -gt 0 ]]; then
        avg_cpu=$(grep ",UP," "$usage_file" | cut -d',' -f3 | awk '{ sum += $1 } END { if (NR > 0) print sum / NR; else print 0 }')
        avg_mem=$(grep ",UP," "$usage_file" | cut -d',' -f4 | awk '{ sum += $1 } END { if (NR > 0) print sum / NR; else print 0 }')
    fi
    
    # Get first and last record timestamps
    local first_record=$(head -n 1 "$status_file" | cut -d',' -f1)
    local last_record=$(tail -n 1 "$status_file" | cut -d',' -f1)
    
    # Display statistics
    gum style --foreground 45 "Service: $name"
    echo ""
    gum style --foreground 220 "Monitoring Period:"
    gum style "First record: $first_record"
    gum style "Last record: $last_record"
    echo ""
    gum style --foreground 220 "Uptime Statistics:"
    gum style "Uptime percentage: $(gum style --foreground 46 "$uptime_pct%")"
    gum style "Total records: $total_records"
    echo ""
    gum style --foreground 220 "Resource Usage:"
    gum style "Current CPU:     $(print_graph $latest_cpu)"
    gum style "Current Memory:  $(print_graph $latest_mem)"
    gum style "Average CPU:     $(print_graph $avg_cpu)"
    gum style "Average Memory:  $(print_graph $avg_mem)"
    
    # Simple ASCII chart for uptime history - show only last 400 records
    UP="$(gum style --foreground 46 "●")"
    DOWN="$(gum style --foreground 196 "●")"
    EMPTY="$(gum style --foreground 240 "●")"  # Dark grey for empty slots

    echo ""
    gum style --foreground 220 "Uptime History (Last 400 minutes):"
    
    # Calculate how many records to display (max 400)
    local display_count=400
    local start_line=1
    
    if [[ $total_records -gt $display_count ]]; then
        start_line=$((total_records - display_count + 1))
    else
        # Fill with empty dots for missing records
        local empty_count=$((display_count - total_records))
        echo -n "  "
        counter=0
        for ((i=1; i<=empty_count; i++)); do
            if (( counter % 20 == 0 && counter > 0 )); then
                echo ""
                echo -n "  "
            fi
            ((counter++))
            echo -n "$EMPTY "
        done
    fi
    
    # Display actual records
    counter=$((counter % 20))  # Continue from where empty dots left off
    tail -n $display_count "$status_file" | while IFS= read -r line; do
        local status=$(echo "$line" | cut -d',' -f2)
        
        # New line when counter is 20
        if (( counter % 20 == 0 && counter > 0 )); then
            echo ""
            echo -n "  "
        fi
        
        ((counter++))
        if [[ "$status" == "UP" ]]; then
            echo -n "$UP "
        else
            echo -n "$DOWN "
        fi
    done

    echo ""
    gum input --placeholder "Press Enter to continue..."
}

# Function to print a graph given a percentage
print_graph () {
    local percentage=$1

    # round percentage to nearest integer
    percentage=$(printf "%.0f" "$percentage")

    if (( percentage < 0 || percentage > 100 )); then
        echo "Invalid percentage: $percentage"
        return
    fi
    if (( percentage == 0 )); then
        echo "0%"
        return
    fi
    local filled_length=$((percentage / 2)) 
    local empty_length=$((50 - filled_length))
    
    local filled_blocks=$(printf '█%.0s' $(seq 1 $filled_length))
    local empty_blocks=$(printf '░%.0s' $(seq 1 $empty_length))
    
    echo -e "${filled_blocks}${empty_blocks} ${percentage}%"
}

start_monitoring() {
    # Check if monitoring script is already running
    if pgrep -f "service_monitor.sh" > /dev/null; then
        return
    fi
    
    # Start the monitoring script in the background
    if [[ -f "service_monitor.sh" ]]; then
        bash service_monitor.sh &
        echo "service_monitor:$!" >> "$PID_FILE"
    else
        gum style --foreground 196 "Monitoring script not found"
    fi
}

# Function to display services menu with gum
display_services_menu() {
    clear
    print_banner "MANAGE SERVICES"
    
    # Create options for gum choose
    local options=()
    local statuses=()
    local display_options=()
    
    for i in "${!services[@]}"; do
        local service="${services[$i]}"
        IFS='|' read -r name command port <<< "$service"
        
        local status=$(check_service_status $i)
        if [ "$status" == "UP" ]; then
            status_color="green"
        else
            status_color="red"
        fi
        
        options+=("$name (Port: $port)")
        statuses+=("$i:$status:$status_color")
    done
    
    
    # Display service list with gum
    gum style --foreground 255 "Select a service to manage:"
    
    # Create display options with status indicators
    for i in "${!options[@]}"; do
        if [ $i -lt ${#statuses[@]} ]; then
            IFS=':' read -r idx status color <<< "${statuses[$i]}"
            if [ "$color" == "green" ]; then
                display_options+=("[38;5;46m●[39m ${options[$i]}")
            else
                display_options+=("[38;5;196m●[39m ${options[$i]}")
            fi
        fi
    done
    
    # Add "Add new service" option
    display_options+=("[38;5;226m+[39m Add new service")
    display_options+=("Exit")

    # Get user choice
    local choice=$(gum choose --limit 1 --cursor="▶ " --cursor.background=146 --cursor.foreground=15 "${display_options[@]}")
    
    # Handle choice
    if [ "$choice" == "Exit" ]; then
        cleanup
    elif [ "$(echo "$choice" | cut -d' ' -f2-)" == "Add new service" ]; then
        add_service
        display_services_menu
    else
        # Extract the service name from the choice by removing the status indicator
        local selected_service=$(echo "$choice" | cut -d' ' -f2-)
        
        # Find the index of the selected service
        for i in "${!options[@]}"; do
            if [ "${options[$i]}" == "$selected_service" ]; then
                selected_index=$i
                break
            fi
        done
        
        # Show action menu for the selected service
        service_action_menu $selected_index
    fi
}

# Function to show action menu for a service
service_action_menu() {
    local index=$1
    local service="${services[$index]}"
    IFS='|' read -r name command port <<< "$service"
    
    local status=$(check_service_status $index)
    local actions=()
    
    if [ "$status" == "UP" ]; then
        actions+=("Stop Service")
    else
        actions+=("Start Service")
    fi
    
    actions+=("View Statistics" "Edit Service" "Delete Service" "Back")
    
    clear
    # Print service name in banner without rainbow colors
    figlet -f small -w 100 -c "$name"
    echo ""
    
    gum style --foreground 45 "Service: $name"
    gum style --foreground 220 "Command: $command"
    gum style --foreground 220 "Port: $port"
    gum style --foreground $([ "$status" == "UP" ] && echo "46" || echo "196") "Status: $status"
    
    # Show URL to access the service if it's running
    if [ "$status" == "UP" ]; then
        echo ""
        gum style --foreground 46 "Access URL: http://$IP:$port"
    fi
    echo ""
    
    local action=$(gum choose --cursor-prefix "▶ " --selected-prefix "▶ " --unselected-prefix "  " "${actions[@]}")
    
    case $action in
        "Start Service")
            start_service $index
            ;;
        "Stop Service")
            stop_service $index
            ;;
        "View Statistics")
            visualize_service_data $index
            service_action_menu $index
            ;;
        "Edit Service")
            edit_service $index
            ;;
        "Delete Service")
            delete_service $index
            ;;
        "Back")
            display_services_menu
            ;;
    esac
}
# Main function
main() {
    # Load services from config
    load_services
    
    # Create data directory if it doesn't exist
    mkdir -p ".server_data"
    
    # Start monitoring script
    start_monitoring
    
    clear
    gum style --foreground 45 "Your local IP is: $(gum style --foreground 220 "$IP")"
    
    gum spin --spinner dot --title "Initializing..." -- sleep 1
    
    # Trap SIGINT (Ctrl+C)
    trap cleanup SIGINT
    
    # Go directly to services menu
    display_services_menu
}


# Start the script
main
