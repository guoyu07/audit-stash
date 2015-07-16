<?php

namespace AuditStash\Test\Model\Behavior;

use AuditStash\Model\Behavior\AuditLogBehavior;
use AuditStash\Event\AuditCreateEvent;
use AuditStash\Event\AuditUpdateEvent;
use AuditStash\PersisterInterface;
use Cake\Event\Event;
use Cake\ORM\Entity;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class DebugPersister implements PersisterInterface
{

    public function logEvents(array $events)
    {
    }
}

class AuditIntegrationTest extends TestCase
{

    public $fixtures = [
        'core.articles',
        'core.comments',
        'core.authors',
        'core.tags',
        'core.articles_tags',
    ];

    public function setUp()
    {
        $this->table = TableRegistry::get('Articles');
        $this->table->hasMany('Comments');
        $this->table->belongsToMany('Tags');
        $this->table->belongsTo('Authors');
        $this->table->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class
        ]);

        $this->persister = $this->getMock(DebugPersister::class);
        $this->table->behaviors()->get('AuditLog')->persister($this->persister);
    }

    public function testCreateArticle()
    {
        $entity = $this->table->newEntity([
            'title' => 'New Article',
            'author_id' => 1,
            'body' => 'new article body'
        ]);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->will($this->returnCallback(function (array $events)  use ($entity) {
                $this->assertCount(1, $events);
                $event = $events[0];
                $this->assertInstanceOf(AuditCreateEvent::class, $event);

                $this->assertEquals(4, $event->getId());
                $this->assertEquals('articles', $event->getSourceName());
                $this->assertEquals($event->getOriginal(), $event->getChanged());
                $this->assertNotEmpty($event->getTransactionId());

                $data = $entity->toArray();
                $this->assertEquals($data, $event->getChanged());
            }));

        $this->table->save($entity);
    }

    public function testUpdateArticle()
    {
        $entity = $this->table->get(1);
        $entity->title = 'Changed title';
        $entity->published = 'Y';

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->will($this->returnCallback(function (array $events)  use ($entity) {
                $this->assertCount(1, $events);
                $event = $events[0];
                $this->assertInstanceOf(AuditUpdateEvent::class, $event);

                $this->assertEquals(1, $event->getId());
                $this->assertEquals('articles', $event->getSourceName());
                $expected = [
                    'title' => 'Changed title',
                    'published' => 'Y'
                ];
                $this->assertEquals($expected, $event->getChanged());
                $this->assertNotEmpty($event->getTransactionId());
            }));

        $this->table->save($entity);
    }

    public function testCreateArticleWithExisitingBelongsTo()
    {
        $entity = $this->table->newEntity([
            'title' => 'New Article',
            'body' => 'new article body'
        ]);
        $entity->author = $this->table->Authors->get(1);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->will($this->returnCallback(function (array $events)  use ($entity) {
                $this->assertCount(1, $events);
                $event = $events[0];
                $this->assertInstanceOf(AuditCreateEvent::class, $event);

                $this->assertEquals(4, $event->getId());
                $this->assertEquals('articles', $event->getSourceName());
                $changed = $event->getChanged();
                $this->assertEquals(1, $changed['author_id']);
                $this->assertFalse(isset($changed['author']));
                $this->assertNotEmpty($event->getTransactionId());
            }));

        $this->table->save($entity);
    }

    public function testUpdateArticleWithExistingBelongsTo()
    {
        $entity = $this->table->get(1, [
            'contain' => ['Authors']
        ]);
        $entity->title = 'Changed title';
        $entity->author = $this->table->Authors->get(2);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->will($this->returnCallback(function (array $events)  use ($entity) {
                $this->assertCount(1, $events);
                $event = $events[0];
                $this->assertInstanceOf(AuditUpdateEvent::class, $event);

                $this->assertEquals(1, $event->getId());
                $this->assertEquals('articles', $event->getSourceName());
                $expected = [
                    'title' => 'Changed title',
                    'author_id' => 2
                ];
                $this->assertEquals($expected, $event->getChanged());
                $this->assertFalse(isset($changed['author']));
                $this->assertNotEmpty($event->getTransactionId());
            }));

        $this->table->save($entity);
    }

    public function testCreateArticleWithNewBelongsTo()
    {
        $this->table->Authors->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class
        ]);
        $entity = $this->table->newEntity([
            'title' => 'New Article',
            'body' => 'new article body',
            'author' => [
                'name' => 'Jose'
            ]
        ]);
        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->will($this->returnCallback(function (array $events)  use ($entity) {
                $this->assertCount(2, $events);
                $this->assertEquals('authors', $events[0]->getSourceName());
                $this->assertEquals('articles', $events[1]->getSourceName());

                $this->assertInstanceOf(AuditCreateEvent::class, $events[0]);
                $this->assertNotEmpty($events[0]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[1]->getTransactionId());

                $this->assertEquals(['id' => 5, 'name' => 'Jose'], $events[0]->getChanged());
                $this->assertFalse(isset($events[1]->getChanged()['author']));
                $this->assertEquals('new article body', $events[1]->getChanged()['body']);
            }));

        $this->table->save($entity);
    }

    public function testUpdateArticleWithHasMany()
    {
        $this->table->Comments->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class
        ]);

        $entity = $this->table->get(1, [
            'contain' => ['Comments']
        ]);
        $entity->comments[] = $this->table->Comments->newEntity([
            'user_id' => 1,
            'comment' => 'This is a comment'
        ]);
        $entity->comments[] = $this->table->Comments->newEntity([
            'user_id' => 1,
            'comment' => 'This is another comment'
        ]);
        $entity->dirty('comments', true);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->will($this->returnCallback(function (array $events)  use ($entity) {
                $this->assertCount(2, $events);
                $this->assertEquals('comments', $events[0]->getSourceName());
                $this->assertEquals('comments', $events[1]->getSourceName());

                $this->assertNotEmpty($events[0]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[1]->getTransactionId());

                $expected = [
                    'id' => 7,
                    'article_id' => 1,
                    'user_id' => 1,
                    'comment' => 'This is a comment'
                ];
                $this->assertEquals($expected, $events[0]->getChanged());

                $expected = [
                    'id' => 8,
                    'article_id' => 1,
                    'user_id' => 1,
                    'comment' => 'This is another comment'
                ];
                $this->assertEquals($expected, $events[1]->getChanged());
            }));

        $this->table->save($entity);
    }

    public function testCreateArticleWithHasMany()
    {
        $this->table->Comments->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class
        ]);

        $entity = $this->table->newEntity([
            'title' => 'New Article',
            'body' => 'new article body',
            'comments' => [
                ['comment' => 'This is a comment', 'user_id' => 1],
                ['comment' => 'This is another comment', 'user_id' => 1],
            ]
        ]);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->will($this->returnCallback(function (array $events)  use ($entity) {
                $this->assertCount(3, $events);
                $this->assertEquals('comments', $events[0]->getSourceName());
                $this->assertEquals('comments', $events[1]->getSourceName());
                $this->assertEquals('articles', $events[2]->getSourceName());

                $this->assertNotEmpty($events[0]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[1]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[2]->getTransactionId());
            }));

        $this->table->save($entity);
    }

    public function testUpdateWithBelongsToMany()
    {
        $this->table->Tags->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class
        ]);
        $this->table->Tags->junction()->addBehavior('AuditLog', [
            'className' => AuditLogBehavior::class
        ]);

        $entity = $this->table->get(1, [
            'contain' => ['Tags']
        ]);
        $entity->tags[] = $this->table->Tags->newEntity([
            'name' => 'This is a Tag'
        ]);
        $entity->tags[] = $this->table->Tags->get(3);
        $entity->dirty('tags', true);

        $this->persister
            ->expects($this->once())
            ->method('logEvents')
            ->will($this->returnCallback(function (array $events)  use ($entity) {
                $this->assertCount(3, $events);
                $this->assertEquals('tags', $events[0]->getSourceName());
                $this->assertEquals('articles', $events[0]->getParentSourceName());
                $this->assertEquals('articles_tags', $events[1]->getSourceName());
                $this->assertEquals('articles', $events[1]->getParentSourceName());
                $this->assertEquals('articles_tags', $events[2]->getSourceName());
                $this->assertEquals('articles', $events[2]->getParentSourceName());

                $this->assertNotEmpty($events[0]->getTransactionId());
                $this->assertSame($events[0]->getTransactionId(), $events[1]->getTransactionId());
            }));

        $this->table->save($entity);
    }
}
