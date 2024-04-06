# Plakos Moodle Rest Plugin

A plugin for moodle that exposes various API endpoints crafted specifically for plakos.

## Development Setup

This is special for moodle as it does not support composer of any kind. This approach is just one
of many, find your own way to solve it.

Maybe https://moodledev.io/general/development/tools/mdk could help?

Create a custom folder (eg. `plakos-moodle-development`). This folder will contain all projects related to the
development of the plugin.

```
mkdir plakos-moodle-development
cd plakos-moodle-development
```

## 1. Clone this plugin

```
git clone git@github:plakos/plakos-moodle-rest-api.git
```

## 2. Clone moodle

Clone the official moodle. Current stable branch is MOODLE_403_STABLE. See here for more infos:
https://download.moodle.org/releases/latest/

```
git clone -b MOODLE_403_STABLE git://git.moodle.org/moodle.git
```

## 3. Clone and setup docker

Clone the official moodle docker implementation and follow the documentation.

```
git clone git@github.com:moodlehq/moodle-docker.git
cd moodle-docker
```

Create `local.yml` file and add the following contents:

```
services:
  webserver:
    volumes:
      - "../moodle:/var/www/html"
      - "../plakos-moodle-rest-api:/var/www/plugin"
      - "../moodle-plugin-ci.phar:/var/www/moodle-plugin-ci.phar"
```

Now configure the moodle instance:

```
export MOODLE_DOCKER_WWWROOT=$ABS/plakos-moodle-development/moodle
export MOODLE_DOCKER_DB=pgsql

cp config.docker-template.php $MOODLE_DOCKER_WWWROOT/config.php

bin/moodle-docker-compose up -d
bin/moodle-docker-wait-for-db

# install demo  / test database
bin/moodle-docker-compose exec webserver php admin/cli/install_database.php --agree-license --fullname="Docker moodle" --shortname="docker_moodle" --summary="Docker moodle site" --adminpass="test" --adminemail="admin@example.com"
```

The instance is running on https://localhost:8000 by default.

Username: admin
Password: test

## 4. Clone moodle-plugin-ci

```
git clone git@github.com:moodlehq/moodle-plugin-ci.git
```

## Symlink plugin into moodle

```
./bin/moodle-docker-compose exec webserver bash -c "ln -s /var/www/plugin /var/www/html/local/ws_plakos"
```

## Endpoints

### `plakos_get_questions`

This endpoint returns a list of questions depending on the given criteria.

#### Parameters

- `courseid`: The ID of the course.
- `types`: A list of moodle question types to be returned.
- `page`: The page offset.
- `perpage`: The max. number of question to be returned from the endpoint.

Only the course id is mandatory, the other parameters have sensible defaults:

- `types`: All questions types are returned.
- `page`: 1
- `perpage`: 100

#### Return values

