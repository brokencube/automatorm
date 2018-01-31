# <img src='https://www.brokencube.co.uk/resource/AutomatormLogo.png?2' alt='AutomatORM'>
[![Latest Stable Version](https://poser.pugx.org/brokencube/automatorm/v/stable)](https://packagist.org/packages/brokencube/automatorm) 
[![Build Status](https://travis-ci.org/brokencube/automatorm.svg?branch=master)](https://travis-ci.org/brokencube/automatorm) 
[![Code Climate](https://codeclimate.com/github/brokencube/automatorm/badges/gpa.svg)](https://codeclimate.com/github/brokencube/automatorm) 

A simple to use ORM in PHP, that reads your database schema directly, without having to run code generators, or create schema documents.

### Installation
```bash
$ composer require brokencube\automatorm
```

### Requirements
PHP 7.0 + PDO (Currently only MySQL supported - expect to support other engines in the future)

### Basic Example
```php
<?php
use Automatorm\Orm\{Model,Schema};
use Automatorm\DataLayer\Database\Connection;

// Class that is linked to the table "blog" - namespace is linked to a particular schema + connection
namespace models {
  class Blog extends Model {}
}

// Get db connection
$connection = Connection::register($pdo); 

// Get db schema for the ORM and assign to 'models' namespace as above
Schema::generate($connection, 'models');  

// Find a table row based on a simple where clause
$blog = Blog::find(['title' => 'My Blog']); // Select * from blog where title = 'My Blog';

echo $blog->id;     // Prints "1"
echo $blog->title;  // Prints "My First Entry"
```

A more detailed layout of how to use the ORM can be found in the [Wiki](https://github.com/brokencube/automatorm/wiki)
