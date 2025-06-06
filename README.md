# How to run


## Install dependencies

__Assuming debian-based linux system__

```sh
sudo apt install figlet bc
```

Then we need to install gum (https://github.com/charmbracelet/gum)

```sh
sudo mkdir -p /etc/apt/keyrings
curl -fsSL https://repo.charm.sh/apt/gpg.key | sudo gpg --dearmor -o /etc/apt/keyrings/charm.gpg
echo "deb [signed-by=/etc/apt/keyrings/charm.gpg] https://repo.charm.sh/apt/ * *" | sudo tee /etc/apt/sources.list.d/charm.list
sudo apt update && sudo apt install gum
```

## Run

```sh
chmod +x start_home_server.sh
./start_home_server.sh
```


