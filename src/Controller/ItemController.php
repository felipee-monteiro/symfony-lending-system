<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Item\LendItemHandler;
use App\Application\Item\RegisterItemHandler;
use App\Application\Item\RenewLoanHandler;
use App\Application\Item\ReturnItemHandler;
use App\Application\Reservation\ReserveItemHandler;
use App\Domain\Item\BorrowerId;
use App\Domain\Item\ItemId;
use App\Domain\Item\ItemStatus;
use App\DTO\Input\LendItemInput;
use App\DTO\Input\RegisterItemInput;
use App\DTO\Input\RenewLoanInput;
use App\DTO\Input\ReserveItemInput;
use App\Form\LendItemType;
use App\Form\RegisterItemType;
use App\Form\RenewLoanType;
use App\Form\ReserveItemType;
use App\ReadModel\ItemFilter;
use App\ReadModel\ItemOverviewQuery;
use App\ReadModel\ItemRow;
use App\ReadModel\OverdueItemsQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Thin HTTP layer: it validates input, calls one handler or one read model, and
 * redirects. No business rule lives here (domain exceptions become flash messages).
 */
final class ItemController extends AbstractController
{
    public function __construct(
        private readonly ItemOverviewQuery $overview,
        private readonly OverdueItemsQuery $overdueItems,
        private readonly LendItemHandler $lendItem,
        private readonly ReturnItemHandler $returnItem,
        private readonly RenewLoanHandler $renewLoan,
        private readonly ReserveItemHandler $reserveItem,
        private readonly FormFactoryInterface $forms,
    ) {}

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute('app_items_index');
    }

    #[Route('/items', name: 'app_items_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = ItemFilter::fromQuery($request->query->getString('filter'));

        $items = match ($filter) {
            ItemFilter::Overdue                     => ($this->overdueItems)(),
            ItemFilter::All                         => ($this->overview)(),
            ItemFilter::Available, ItemFilter::Lent => $this->filterByStatus(($this->overview)(), $filter),
        };

        return $this->render('item/index.html.twig', [
            'items'        => $items,
            'filter'       => $filter,
            'lendForms'    => $this->lendForms($items),
            'renewForms'   => $this->renewForms($items),
            'reserveForms' => $this->reserveForms($items),
        ]);
    }

    #[Route('/items/new', name: 'app_items_new', methods: ['GET', 'POST'])]
    public function new(Request $request, RegisterItemHandler $registerItem): Response
    {
        $input = new RegisterItemInput;
        $form  = $this->createForm(RegisterItemType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $item = $registerItem((string) $input->title);
            $this->addFlash('success', \sprintf('Item "%s" registered.', $item->title()));

            return $this->redirectToRoute('app_items_index');
        }

        return $this->render('item/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/items/{id}/loans', name: 'app_items_lend', methods: ['POST'])]
    public function lend(string $id, Request $request): Response
    {
        $form = $this->forms->createNamed('lend_'.$id, LendItemType::class);
        $form->handleRequest($request);

        if (! $form->isSubmitted() || ! $form->isValid()) {
            return $this->rejectForm($form);
        }

        /** @var LendItemInput $input */
        $input = $form->getData();

        if ($input->dueAt === null) {
            throw new \LogicException('A validated lend input must carry a due date.');
        }

        ($this->lendItem)(
            ItemId::fromString($id),
            BorrowerId::fromString((string) $input->borrowerId),
            $input->dueAt,
        );

        $this->addFlash('success', 'Item lent.');

        return $this->redirectToRoute('app_items_index');
    }

    #[Route('/items/{id}/reservations', name: 'app_items_reserve', methods: ['POST'])]
    public function reserve(string $id, Request $request): Response
    {
        $form = $this->forms->createNamed('reserve_'.$id, ReserveItemType::class);
        $form->handleRequest($request);

        if (! $form->isSubmitted() || ! $form->isValid()) {
            return $this->rejectForm($form);
        }

        /** @var ReserveItemInput $input */
        $input = $form->getData();

        ($this->reserveItem)(
            ItemId::fromString($id),
            BorrowerId::fromString((string) $input->borrowerId),
        );

        $this->addFlash('success', 'Reservation placed.');

        return $this->redirectToRoute('app_items_index');
    }

    #[Route('/items/{id}/return', name: 'app_items_return', methods: ['POST'])]
    public function returnItem(string $id, Request $request): Response
    {
        if (! $this->isCsrfTokenValid('submit', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_items_index');
        }

        ($this->returnItem)(ItemId::fromString($id));

        $this->addFlash('success', 'Item returned.');

        return $this->redirectToRoute('app_items_index');
    }

    #[Route('/items/{id}/renewals', name: 'app_items_renew', methods: ['POST'])]
    public function renew(string $id, Request $request): Response
    {
        $form = $this->forms->createNamed('renew_'.$id, RenewLoanType::class);
        $form->handleRequest($request);

        if (! $form->isSubmitted() || ! $form->isValid()) {
            return $this->rejectForm($form);
        }

        /** @var RenewLoanInput $input */
        $input = $form->getData();

        if ($input->newDueAt === null) {
            throw new \LogicException('A validated renewal input must carry a due date.');
        }

        ($this->renewLoan)(ItemId::fromString($id), $input->newDueAt);

        $this->addFlash('success', 'Loan renewed.');

        return $this->redirectToRoute('app_items_index');
    }

    /**
     * @param  ItemRow[]  $items
     * @return ItemRow[]
     */
    private function filterByStatus(array $items, ItemFilter $filter): array
    {
        return array_values(array_filter(
            $items,
            static fn (ItemRow $row): bool => $row->status === $filter->value,
        ));
    }

    /**
     * One lend form per available item: the form name carries the item id so rows do
     * not clash on the same request and the POST action can rebuild the same form.
     *
     * @param  ItemRow[]  $items
     * @return array<string, FormView>
     */
    private function lendForms(array $items): array
    {
        $forms = [];

        foreach ($items as $row) {
            if (ItemStatus::Available->value !== $row->status) {
                continue;
            }

            $forms[$row->itemId] = $this->forms->createNamed(
                'lend_'.$row->itemId,
                LendItemType::class,
                null,
                ['action' => $this->generateUrl('app_items_lend', ['id' => $row->itemId])],
            )->createView();
        }

        return $forms;
    }

    /**
     * @param  ItemRow[]  $items
     * @return array<string, FormView>
     */
    private function renewForms(array $items): array
    {
        $forms = [];

        foreach ($items as $row) {
            if (ItemStatus::Lent->value !== $row->status) {
                continue;
            }

            $forms[$row->itemId] = $this->forms->createNamed(
                'renew_'.$row->itemId,
                RenewLoanType::class,
                null,
                ['action' => $this->generateUrl('app_items_renew', ['id' => $row->itemId])],
            )->createView();
        }

        return $forms;
    }

    /**
     * One reservation form per lent item, same naming scheme as the lend/renew forms.
     *
     * @param  ItemRow[]  $items
     * @return array<string, FormView>
     */
    private function reserveForms(array $items): array
    {
        $forms = [];

        foreach ($items as $row) {
            if (ItemStatus::Lent->value !== $row->status) {
                continue;
            }

            $forms[$row->itemId] = $this->forms->createNamed(
                'reserve_'.$row->itemId,
                ReserveItemType::class,
                null,
                ['action' => $this->generateUrl('app_items_reserve', ['id' => $row->itemId])],
            )->createView();
        }

        return $forms;
    }

    private function rejectForm(FormInterface $form): Response
    {
        $message = 'Invalid form data.';

        foreach ($form->getErrors(true) as $error) {
            $message = $error->getMessage();

            break;
        }

        $this->addFlash('error', $message);

        return $this->redirectToRoute('app_items_index');
    }
}
