# Caretaker2 Hub

Central management and monitoring for TYPO3 instances: the hub takes the
inventories of connected instances in, evaluates them and shows the result
in a backend module. Requires TYPO3 v14 and a `composer` binary with access
to Packagist.

The counterpart in each monitored instance is
[caretaker2/agent](https://github.com/etobi/typo3-caretaker2-agent).

```
composer require caretaker2/hub
```

Then create the scheduler tasks the *Caretaker2 → Instances* module asks
for, and connect the first instance with the code the module hands out.

## Behind Apache

The agent sends its token as `Authorization: Bearer <token>`. Apache does
not pass that header on to PHP-FPM or CGI by default, so an instance
connects but never reports, and its first push fails with HTTP 401. Put
`CGIPassAuth On` into the vhost or the `.htaccess` in the web root.

## Read-only mirror

This repository is a read-only release mirror. Development happens in a
private monorepo; issues and pull requests here are not monitored.

## License

GPL-2.0-or-later, see `LICENSE`.
