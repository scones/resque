[![Build Status](https://travis-ci.com/scones/resque.svg?branch=master)](https://travis-ci.com/scones/resque)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/scones/resque/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/scones/resque/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/scones/resque/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/scones/resque/?branch=master)


# Resque

PHP implementation of the resque mechanism.

This is a completely new implementation featuring things as
- no static methods
- minimalistic dependencies (only core features for the asynchronous task processing)
- good coding standards
- psr events as tasks, thus the behavior can be modified
- strict typing

## Install

In most cases it should suffice to just install it via composer.

`composer require scones/resque "*@stable"`

## How does it work?

The resque task processing is a decentral queueing system. (read: no master server or similar)
There are two parts to the processing, pushing jobs and working job. Those should not run in the same application or at least process to gain the most benefit from it.

### pushing jobs

The first step to process a job is to put it on the queue. Assuming you have the resque class already instantiated, you'd be just calling `$resque->enqueue('SomeClass', ['some' => 'data'], 'some_queue')`

This method puts the task on the queue and returns. It will not process it, as it is not its job.
The data is stored (in a very precise format) in a redis database.

### working jobs

The second step to process a job is the worker.
The worker is an process running anywhere with access to the same redis database.

The worker waits new entries in the configured queues and fetches one, as soon as it's available.
When this happens, it builds the corresponding class (from the supplied container/servicelocator, see examples) and invokes `perform` with the array provided in enqueue as arguments.

## Examples

Find runnable usage examples under https://github.com/scones/resque-examples
