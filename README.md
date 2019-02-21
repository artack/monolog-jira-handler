JIRA Handler for Monolog
========================

[![Build Status](https://img.shields.io/travis/ARTACK/monolog-jira-handler.svg?style=flat)](https://travis-ci.org/ARTACK/monolog-jira-handler)
[![Scrutinizer Quality Score](https://img.shields.io/scrutinizer/g/artack/monolog-jira-handler.svg?style=flat)](https://scrutinizer-ci.com/g/artack/monolog-jira-handler/)
[![Scrutinizer Coverage](https://img.shields.io/scrutinizer/coverage/g/artack/monolog-jira-handler.svg)](https://scrutinizer-ci.com/g/artack/monolog-jira-handler/)
[![Latest Release](https://img.shields.io/packagist/v/artack/monolog-jira-handler.svg)](https://packagist.org/packages/artack/monolog-jira-handler)
[![MIT License](https://img.shields.io/packagist/l/artack/monolog-jira-handler.svg)](http://opensource.org/licenses/MIT)
[![Total Downloads](https://img.shields.io/packagist/dt/artack/monolog-jira-handler.svg)](https://packagist.org/packages/artack/monolog-jira-handler)

Developed by [ARTACK WebLab GmbH](https://www.artack.ch) in Zurich, Switzerland.

Introduction
------------
This handler will write the logs to a JIRA instance. The handler will calculate a hash over the log-data except 
time sensitive data. It then will query the JIRA REST API to determe if there is already a JIRA Issue with the
corresponding hash. If so, the handler will do nothing. If there is no issue matching the hash the handler will 
create a new issue with the content of the log entry.

Installation
------------
You can install it through [Composer](https://getcomposer.org):

```shell
$ composer require artack/monolog-jira-handler
```

Basic usage
-----------
tbd.
