# SilverStripe Mailer for sending mail via the Mailgun API

This module lets you send SilverStripe emails through the [official Mailgun PHP library](https://github.com/mailgun/mailgun-php), falling back to PHP's built-in `sendmail()` if Mailgun is unreachable.

## Requirements
 * PHP 5.4+
 * SilverStripe ~3.1
 * [Mailgun-PHP](https://github.com/mailgun/mailgun-php)
 * (optional) set up a manual cron task, or use silverstripe-crontask or silverstripe-queuedjobs(?) to keep log synced

## Installation
Install with Composer. [Learn how](https://docs.silverstripe.org/en/getting_started/composer/#adding-modules-to-your-project)

```
composer require "micschk/silverstripe-mailgun-mailer:~1.0"
```

## Documentation

You will need to provide a Mailgun API key for a verified domain that you have set up in your [Mailgun account](https://mailgun.com/app/domains/).

Also, if you want to synchronize the Mailgun log, you will need to set up some way to run/ Mailgun_SyncLogTask::poll() every not and then. This gets the Mailgun events log from the API and saves it to the local database so you can see when messages got sent, openend and/or bounced etc.

## Example configuration

In your project's `_config/config.yml` file:

```yaml
MailgunMailer:
  api_key: 'key-goes-here'
  api_domain: 'verified-domain'
```

In your project's `_config.php` file:

```php
Injector::inst()->registerService(new MailgunMailer(), 'Mailer');
```

or:

```php
// Send email through Mailgun in live environment only
if (Director::isLive()) {
	Injector::inst()->registerService(new MailgunMailer(), 'Mailer');
}
```
