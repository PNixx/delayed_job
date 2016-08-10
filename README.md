#Delayed Job PHP

Simple, efficient background processing for PHP uses threads to handle many jobs.

##Requirements

* PHP 5.6+
* Redis 2.2+
* Composer

##Installation

```sh
composer require pnixx/delayed_job
```

##Worker process

Simple run worker process in background:

```sh
bin/run --quiet
```

Extend run worker process:

```sh
bin/run --process 5 --queue mailer -i /path/to/init.php --log_level debug --quiet
```

For list all commands, please use `--help` or `-h` argument.

For restart process after deploy use `--restart` or `-r` argument. A new process will be waiting finish all running processes.

##Jobs

Job class required include `perform` method:

```php
class TestJob extends PNixx\DelayedJob\Job {

	public function perform($args = []) {
		//Work process
	}
}
```

Any exception thrown by a job will be returned job to work with timeout.
If you want set limit attempt for retries job, set `$attempt` in you class.

Jobs can also have `setup` and `completed` methods. If a `setup` method is defined, it will be called before the `perform` method.
The `completed` method will be called after success job.

```php
class TestJob extends PNixx\DelayedJob\Job {

	/**
	 * Attempt count used for only delayed tasks
	 * default: 0 - always repeat until it reach success
	 */
	public static $attempt = 0;

	public function setup() {
		//Setup this job
	}

	public function perform($args = []) {
		//Work process
	}

	public function completed() {
		//Complete job callback
	}
}
```

##Queueing jobs

Jobs can run in current thread uses `now` method. If you use this, you can handle the exceptions in a job failing.

```php
//Run job in this thread without arguments
TestJob::now();

//Run job in this thread with arguments
TestJob::now(['name' => 'Jane']);
```

Jobs can run in a background thread or add to scheduler.

```php
//Run job in a background
TestJob::later('mailer');

//Run job in a background with arguments
TestJob::later('mailer', ['name' => 'Jane']);

//Add job in a scheduler.
TestJob::later('mailer', ['name' => 'Jane'], strtotime('+1 day'));
```

##Signals

* `QUIT` - Wait for job to finish processing then exit
* `TERM` / `INT` - Immediately kill job then exit without saving data

##Author

Sergey Odintsov, [@pnixx](https://new.vk.com/djnixx)