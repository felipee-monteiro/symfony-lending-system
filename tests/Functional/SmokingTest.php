<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SmokingTest extends WebTestCase
{
    #[DataProvider('urlProvider')]
    public function test_page_is_successful(string $url): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->followRedirects();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function urlProvider(): iterable
    {
        yield 'home' => ['/'];

        yield 'listing' => ['/items'];

        yield 'new item' => ['/items/new'];
    }
}
