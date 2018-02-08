![QUIQQER RabbitMQ Server](bin/images/Readme.jpg)

QUIQQER RabbitMQ Server
========

This plugin enables the usage of the RabbitMQ message queue within QUIQQER. Create your own worker classes and queue them in a job-queue that is processed asynchronously.
This package requires the installation of the RabbitMQ server. 

Package Name:

    quiqqer/rabbitmqserver

Features
--------
* Run a php server script that dynamically fetches jobs from a RabbitMQ message queue and starts specialized Worker processes
that execute individual tasks
* Set the number of parallel RabbitConsumer php processes that fetch jobs as soon as they are available
* Separate your consumers into normal and high priority consumers to better balance between fast and heavy-load jobs
* JobChecker - Checks jobs for irregularities like a too long queue or execution time

Installation
------------
The Package Name is: quiqqer/rabbitmqserver

* RabbitMQ Server (https://www.rabbitmq.com/download.html); current version: `3.6.5`
* Setup of RabbitMQ Server account(s) (https://dev.quiqqer.com/quiqqer/rabbitmqserver/wikis/user-config)
* Configration of the RabbitMQ login credentials
  * You can test your configuration with the CLI tool `quiqqer:rabbitmqserver`
* `pcntl`-extenstion for PHP (http://php.net/manual/de/book.pcntl.php)
* `bcmath`-extension for PHP (http://php.net/manual/de/book.bc.php)
* `mbstring`-extension for PHP (http://php.net/manual/de/book.mbstring.php)
* Configure and run `RabbitConsumerServer`-daemons (s. https://dev.quiqqer.com/quiqqer/rabbitmqserver/wikis/daemon-config)

Contribute
----------
- Project: https://dev.quiqqer.com/quiqqer/rabbitmqserver
- Issue Tracker: https://dev.quiqqer.com/quiqqer/rabbitmqserver/issues
- Source Code: https://dev.quiqqer.com/quiqqer/rabbitmqserver/tree/master

Support
-------
If you found any errors, have wishes or suggestions for improvement,
you can contact us by email at support@pcsg.de.

We will transfer your request to the responsible developers.

Usage
-------
See the [Wiki](https://dev.quiqqer.com/quiqqer/queuemanager/wikis/home) for setup instructions

License
-------
GPL-3.0+
