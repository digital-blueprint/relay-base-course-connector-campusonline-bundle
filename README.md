# DbpRelayBaseCourseConnectorCampusonlineBundle

[GitHub](https://github.com/digital-blueprint/relay-base-course-connector-campusonline-bundle)

Base Symfony bundle for CampusOnline Course integration for the DBP Relay API Server

## Integration into the Relay API Server

* Add the bundle package as a dependency:

```bash
composer require dbp/relay-base-course-connector-campusonline-bundle
```

* Add the bundle to your `config/bundles.php`:

```php
...
Dbp\Relay\BasePersonBundle\DbpRelayBaseCourseBundle::class => ['all' => true],
Dbp\Relay\BasePersonBundle\DbpRelayBaseCourseConnectorCampusonlineBundle::class => ['all' => true],
...
```

* Run `composer install` to clear caches

## Configuration

The bundle has some configuration values that you can specify in your
app, either by hard-coding it, or by referencing an environment variable.

For this create `config/packages/dbp_relay_base_course_connector_ldap.yaml` in the app with the following
content:

```yaml
dbp_relay_base_course_connector_campusonline:
  campus_online:
    api_token: '%env(CAMPUS_ONLINE_API_TOKEN)%'
    api_url: '%env(CAMPUS_ONLINE_API_URL)%'
    org_root_id: '%env(ORGANIZATION_ROOT_ID)%'
```

For more info on bundle configuration see
https://symfony.com/doc/current/bundles/configuration.html

## Events

### CoursePostEvent

This event allows you to add additional attributes ("local data") to the `\Dbp\Relay\BaseCourseBundle\Entity\Course` base-entity that you want to be included in responses to `Course` entity requests.
Event subscribers receive a `\Dbp\Relay\RelayBaseCourseConnectorCampusonlineBundle\Event\CourseProviderPostEvent` instance containing the `Course` base-entity and the course data provided by Campusonline.

For example, create an event subscriber `src/EventSubscriber/CourseEventSubscriber.php`:

```php
<?php
namespace App\EventSubscriber;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CourseEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            CoursePostEvent::NAME => 'onPost',
    ];
    }

    public function onPost(CoursePostEvent $event)
    {
        $course = $event->getCourse();
        $courseData = $event->getCourseData();
        $course->trySetLocalDataValue('code', $courseData->getCode());
    }
}
```

And add it to your `src/Resources/config/services.yaml`:

```yaml
App\EventSubscriber\CourseEventSubscriber:
  autowire: true
  autoconfigure: true
```
