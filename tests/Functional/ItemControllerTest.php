<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Item\BorrowerId;
use App\Domain\Item\Item;
use App\Domain\Item\ItemId;
use App\ReadModel\ItemOverviewQuery;
use App\ReadModel\ItemRow;
use App\Repository\DoctrineItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Response;

final class ItemControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;

    public function test_registering_an_item_redirects_to_the_listing(): void
    {
        $client = $this->clientWithSchema();

        $client->request('GET', '/items/new');
        self::assertResponseIsSuccessful();

        $client->submitForm('Salvar Item', ['register_item[title]' => 'Ladder']);

        self::assertResponseRedirects('/items');
        $client->followRedirect();

        self::assertSelectorTextContains('table', 'Ladder');
        self::assertSelectorTextContains('.flash', 'Item "Ladder" registered.');
    }

    public function test_an_empty_title_is_rejected(): void
    {
        $client = $this->clientWithSchema();

        $client->request('GET', '/items/new');
        $client->submitForm('Salvar Item', ['register_item[title]' => '']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('This value should not be blank.', (string) $client->getResponse()->getContent());

        /** @var DoctrineItemRepository $items */
        $items = self::getContainer()->get(DoctrineItemRepository::class);
        self::assertSame([], $items->allWithLoans());
    }

    public function test_home_redirects_to_the_listing(): void
    {
        $client = $this->clientWithSchema();

        $client->request('GET', '/');

        self::assertResponseRedirects('/items');
    }

    public function test_index_lists_items_with_their_loan_state(): void
    {
        // Arrange
        $client = $this->clientWithSchema();
        $this->seedAvailable('item-1', 'Drill');
        $this->seedLent('item-2', 'Ladder', 'borrower-1', '2026-02-20 09:00:00');

        // Act
        $client->request('GET', '/items');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Drill');
        self::assertSelectorTextContains('table', 'Ladder');
        self::assertSelectorTextContains('table', 'Disponível');
        self::assertSelectorTextContains('table', 'Emprestado');
        self::assertSelectorTextContains('table', 'borrower-1');
        self::assertSelectorTextContains('table', '20/02/2026');
    }

    public function test_overdue_filter_lists_only_late_items(): void
    {
        // Arrange
        $client = $this->clientWithSchema();
        $this->seedLent('item-1', 'Late drill', 'borrower-1', '2026-02-05 09:00:00');
        $this->seedLent('item-2', 'On time ladder', 'borrower-2', '2026-02-20 09:00:00');
        $this->seedAvailable('item-3', 'Saw');

        // Act
        $client->request('GET', '/items?filter=overdue');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Late drill');
        self::assertSelectorTextNotContains('table', 'On time ladder');
        self::assertSelectorTextNotContains('table', 'Saw');
        self::assertSelectorTextContains('table', 'dia(s)');
    }

    public function test_lending_an_available_item_updates_the_read_model(): void
    {
        // Arrange
        $client = $this->clientWithSchema();
        $this->seedAvailable('item-1', 'Drill');

        // Act
        $client->request('POST', '/items/item-1/loans', [
            'lend_item-1' => [
                'borrowerId' => 'borrower-9',
                'dueAt'      => '2026-02-20',
                '_token'     => $this->csrfToken($client),
            ],
        ]);

        // Assert
        self::assertResponseRedirects('/items');

        $row = $this->rowByTitle($this->overview(), 'Drill');
        self::assertSame('lent', $row->status);
        self::assertSame('borrower-9', $row->borrowerId);
        self::assertSame('2026-02-20', $row->effectiveDueAt?->format('Y-m-d'));
        self::assertSame(0, $row->daysLate);

        $client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Item lent.');
    }

    public function test_renewing_an_open_loan_pushes_the_due_date(): void
    {
        // Arrange
        $client = $this->clientWithSchema();
        $this->seedLent('item-1', 'Drill', 'borrower-1', '2026-02-08 09:00:00');

        // Act
        $client->request('POST', '/items/item-1/renewals', [
            'renew_item-1' => [
                'newDueAt' => '2026-02-20',
                '_token'   => $this->csrfToken($client),
            ],
        ]);

        // Assert
        self::assertResponseRedirects('/items');

        $row = $this->rowByTitle($this->overview(), 'Drill');
        self::assertSame('lent', $row->status);
        self::assertSame('2026-02-20', $row->effectiveDueAt?->format('Y-m-d'));
        self::assertSame(0, $row->daysLate);

        $client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Loan renewed.');
    }

    public function test_returning_an_open_loan_makes_the_item_available(): void
    {
        // Arrange
        $client = $this->clientWithSchema();
        $this->seedLent('item-1', 'Drill', 'borrower-1', '2026-02-08 09:00:00');

        // Act
        $client->request('POST', '/items/item-1/return', ['_token' => $this->csrfToken($client)]);

        // Assert
        self::assertResponseRedirects('/items');

        $row = $this->rowByTitle($this->overview(), 'Drill');
        self::assertSame('available', $row->status);
        self::assertNull($row->borrowerId);
        self::assertNull($row->daysLate);

        $client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Item returned.');
    }

    public function test_lending_an_already_lent_item_flashes_an_error(): void
    {
        // Arrange
        $client = $this->clientWithSchema();
        $this->seedLent('item-1', 'Drill', 'borrower-1', '2026-02-20 09:00:00');

        // Act
        $client->request('POST', '/items/item-1/loans', [
            'lend_item-1' => [
                'borrowerId' => 'borrower-2',
                'dueAt'      => '2026-02-20',
                '_token'     => $this->csrfToken($client),
            ],
        ]);

        // Assert
        self::assertResponseRedirects('/items');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Item is already lent.');

        $row = $this->rowByTitle($this->overview(), 'Drill');
        self::assertSame('borrower-1', $row->borrowerId);
        self::assertCount(1, $this->items()->get(ItemId::fromString('item-1'))->loans());
    }

    public function test_a_due_date_in_the_past_is_rejected_by_the_form(): void
    {
        // Arrange
        $client = $this->clientWithSchema();
        $this->seedAvailable('item-1', 'Drill');

        // Act
        $client->request('POST', '/items/item-1/loans', [
            'lend_item-1' => [
                'borrowerId' => 'borrower-1',
                'dueAt'      => '2026-02-09',
                '_token'     => $this->csrfToken($client),
            ],
        ]);

        // Assert
        self::assertResponseRedirects('/items');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'The date cannot be in the past.');

        $row = $this->rowByTitle($this->overview(), 'Drill');
        self::assertSame('available', $row->status);
    }

    private function clientWithSchema(): KernelBrowser
    {
        self::mockTime(new \DateTimeImmutable('2026-02-10 09:00:00'));

        $client = self::createClient();
        $client->disableReboot();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $client;
    }

    /**
     * Forms share the "submit" CSRF token id, so any rendered form provides the value
     * for the direct POSTs in these tests.
     */
    private function csrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/items/new');

        // Direct POSTs below would otherwise inherit /items/new as the Referer, and the
        // exception subscriber redirects back to the Referer.
        $client->setServerParameter('HTTP_REFERER', 'http://localhost/items');

        return (string) $crawler->filter('input[name="register_item[_token]"]')->attr('value');
    }

    private function items(): DoctrineItemRepository
    {
        return self::getContainer()->get(DoctrineItemRepository::class);
    }

    private function seedAvailable(string $id, string $title): void
    {
        $this->items()->save(new Item(ItemId::fromString($id), $title));
    }

    private function seedLent(string $id, string $title, string $borrowerId, string $dueAt): void
    {
        $item = new Item(ItemId::fromString($id), $title);
        $item->lendTo(
            BorrowerId::fromString($borrowerId),
            new \DateTimeImmutable('2026-02-01 09:00:00'),
            new \DateTimeImmutable($dueAt),
        );
        $this->items()->save($item);
    }

    /**
     * @return ItemRow[]
     */
    private function overview(): array
    {
        return (self::getContainer()->get(ItemOverviewQuery::class))();
    }

    /**
     * @param  ItemRow[]  $rows
     */
    private function rowByTitle(array $rows, string $title): ItemRow
    {
        foreach ($rows as $row) {
            if ($row->title === $title) {
                return $row;
            }
        }

        self::fail(\sprintf('No read model row titled "%s".', $title));
    }
}
