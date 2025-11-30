a very 'crude' way on getting system informations fro ma remote host.

## Preperation
- generate a ssh key on the machine that checks
  ```ssh-keygen -t ed25519 -f ~/.ssh/id_nodestatus -C "NodeHealth"```
- distribute the public key to ~/.ssh/authorized keys on the cleints
- copy nodestatus.sh to the clients

## requires the following features to work
- sensors
- tailscale

## Use
execute on the checking machine 
```
ssh -i ~/.ssh/id_nodestatus user@yourhost /path/to/nodestatus.sh > some.json
```

if the server to check is acessed trough tailscale use this command to avoid DNS errors
```
ssh -i ~/.ssh/id_nodestatus user@$(tailscale ip -4 yourTailscaleHost.ts.net) "sh /path/to/nodestatus.sh"
```

to check all tailscalehosts at once (via cronjob)
use nodecheck.sh

this requires a .env file in the same directory 
example of .env
```
# location of the ssh key used to remote execute nodestatus.sh
KEYFILE="$HOME/.ssh/id_nodecheck"

# Output file prefix (final name becomes prefix + hostname + .json)
OUTFILE_PREFIX="stats_"

# List of Tailscale hosts
# all hosts must have the ~/nodestatus.sh locally avaiable
HOSTS=(
    "us.echo-bu.ts.net"
    "eu.echo-externalnet.ts.net"
    "ap.echo-bu.ts.net"
)
```

## Php export (for remote use)
```
use nodecheck.php?host=your.tailscale.ts.net
```
to get a json array of the specific host with all informations

