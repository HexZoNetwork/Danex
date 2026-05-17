# Wings Certificate Selection Regression

When panel `APP_URL` and node FQDN differ, Wings guard must use the node FQDN certificate. Example:

- APP_URL: `https://hecker.el7.web.id`
- Node FQDN: `nodes.el7.web.id`
- Expected certificate path: `/etc/letsencrypt/live/nodes.el7.web.id/fullchain.pem`
