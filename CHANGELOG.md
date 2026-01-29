# Changelog

## v0.2.1

- for a start only cache one semster back and ahead to not overcrowd existing course selectors which don't filter by semester yet

## v0.2.0

- Remove Legacy XML API variant
- Add implementation for BaseCourseEvent
- Add various local data source attributes (course groups, description, learning objectives, etc.)

## v0.1.27

- Public REST API: Add attribute `courseIdentyCodeUid`
- Add cache refresh cron job for the Public Rest API variant
- Improve exception handling for Public Rest API variant

## v0.1.26

- fix pagination for course collection

## v0.1.25

- Add local data attribute `attendees`
- Make Public Rest API variant production ready

## v0.1.24

- Add local data attribute `lecturers`

## v0.1.23

- Add support for Campusonline Public REST API, where courses are cached in a database. Which API to use (legacy XML or Public REST) can be
set using the bundle configuration.
- Add support for Symfony 7
- Drop support for Symfony 5

## v0.1.22

- dependency cleanup

## v0.1.21

- Extend caching of courses to one day.

## v0.1.20

- Add course code

## v0.1.18

- Refactor and modernize

## v0.1.17

- Port to PHPUnit 10

## v0.1.16

- Add support for api-platform 3.2

## v0.1.15

- Add support for Symfony 6

## v0.1.14

- Drop support for PHP 7.4/8.0

## v0.1.13

- Drop support for PHP 7.3

## v0.1.10

- Use the global "cache.app" adapter for caching instead of always using the filesystem adapter