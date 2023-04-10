# DB Dump File Anonymizer

## Overview

This PHP library is able to anonymize a DB dump file. It is especially useful to apply anonymization when restoring a live DB in a non-live env. This way, if your non-live DB is stolen, you do not have risks to be exposing real customer data, for example.

## How it works

It reads a file with the DB dump in the local storage, then it produces a new DB dump file (or outputs in STDOUT) based on the instructions about which columns should be anonymized.

It is much faster than trying to run multiple `UPDATE` requests to a running DB, because the DB needs to handle with many things like: checking the uniqueness of some columns, indexing, and stuff like that.

## Install

To install this library for usage, just run:

```
composer require printi/db-dump-file-anonymizer
```

## Usage

This library exports an executable script called `anonymize-db-dump`. It is installed in the folder `./vendor/bin/` when the library is required.

The script can be called this way:

```
$ ./vendor/bin/anonymize-db-dump [OPTIONS]
```

These are the available options

 * -i | --input=FILE                   to inform the input file (dump file of MySQL)
 * -o | --output=FILE                  to inform the output file
 * -s | --stdout                       to generate the output in the STDOUT
 * -m | --modifications=MODIFICATIONS  to inform the JSON of expected modifications
 * -l | --locale=LOCALE                to inform the locale to be used by Faker
 * -q | --quiet                        to ommit messages

This library uses [Faker](https://fakerphp.github.io/) to generate the fake (anonymous) data. You will need to check the library to see the available options of formatters and locales.

Simple example:

```
$ php ./vendor/bin/anonymize-db-dump -i ./sample/dump.sql -o ./sample/dump.out.sql -m "$(cat ./sample/modifications.json)" -l pt_BR
```

The example above will read the file `./sample/dump.sql`, then produce a modified dump file `./sample/dump.out.sql` using the modifications specified in the file `./sample/modifications.json` and using the locale `pt_BR` (Brazilian Portuguese) to generate the fake data with localization.

If you want to generate the modified dump file in the STDOUT, you may replace `-o ./sample/dump.out.sql` by `-s` or `--stdout`. This option is useful if you do not want to store the modified dump file and use the modified dump file directly to the mysql command to restore the data like the example bellow:

```
$ php ./vendor/bin/anonymize-db-dump -i ./sample/dump.sql -s -m "$(cat ./sample/modifications.json)" -l pt_BR | mysql -uroot -proot -h localhost -D dbname
```

## Specification of the JSON of modifications

The JSON to specify the modifications over the dump file uses this structure:

```
{
  "<TABLE_NAME>": {
    "<COLUMN_POSITION>": {
      "quote": <BOOLEAN_VALUE>,
      "format": <STRING_VALUE>,
      "unique": <BOOLEAN_VALUE>,
      "optional": <BOOLEAN_VALUE>,
      "optional_weight": <FLOAT_VALUE>
    }
  }
}
```

Basically, we need to specify the tables names, then the column positions of each table (starting from position 1), then the specification of the column.

The "quote" option is used to inform the value should be delimited by quotes (true) or not (false). For example, numeric values does not need to use quotes.

The "format" option is used to specify the format of the column according to the Faker formaters.

The "unique" option is used to specify the column is unique and should not repeat for that column of that table (it might repeat on other columns or other tables).

The "optional" option is used to specify whether the column is optional or not. When a column is optional, the library may select the default value for the field (if the default value is not specified, NULL is used).

The "optional_weight" is used to specify the probability to have an empty value. The value 0.0 indicates the default value will always be used, 1.0 indicates the default value will never be used, 0.2 indicates there is 20% of chance to use the default value.

The "optional_default_value" is used to specify the default value.

Example of modifications:
```
{
  "customers": {
    "2": {
      "quote": true,
      "format": "firstName"
    },
    "3": {
      "quote": true,
      "format": "lastName"
    },
    "4": {
      "quote": true,
      "format": "email",
      "unique": true
    },
    "5": {
      "quote": true,
      "format": "phoneNumber",
      "optional": true,
      "optional_weight": 0.9
    }
  }
}
```
