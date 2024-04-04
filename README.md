# Plakos Moodle Rest Plugin

A plugin for moodle that exposes various API endpoints crafted specifically for plakos.

## Development Setup

This is special for moodle as it does not support composer of any kind. This approach is just one
of many, find your own way to solve it.

Maybe https://moodledev.io/general/development/tools/mdk could help?

Create a custom folder (eg. `plakos-moodle-development`). This folder will contain all projects related to the 
development of the plugin. The following commands expect you to be in this folder.

## 1. Setup docker

Clone the official moodle docker implementation and follow the documentation.

```
git clone https://github.com/moodlehq/moodle-docker
```

## 2. Setup local moodle-plugin-ci

## 3. Setup library

 - clone
 - symlink into moodle docker checkout?


### Testing

TODO: Check moodle docker to run tests

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

