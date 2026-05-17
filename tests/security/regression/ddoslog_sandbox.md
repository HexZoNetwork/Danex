# DDoS Logger Sandbox Regression

`pteroprotect-ddoslog.service` must be able to read nginx logs under `www-data:adm 0640` without weakening the service to full host access. Expected unit property:

- `SupplementaryGroups=adm www-data`
