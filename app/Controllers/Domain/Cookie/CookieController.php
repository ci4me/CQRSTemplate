<?php

declare(strict_types=1);

namespace App\Controllers\Domain\Cookie;

use App\Controllers\BaseController;
use App\Domain\Cookie\Commands\CreateCookie\CreateCookieCommand;
use App\Domain\Cookie\Commands\DeleteCookie\DeleteCookieCommand;
use App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieCommand;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdQuery;
use App\Domain\Cookie\Queries\GetCookiesPaginated\GetCookiesPaginatedQuery;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Services;

/**
 * Cookie Controller - UI Layer.
 *
 * Responsibilities:
 * - Handle HTTP requests/responses
 * - Dispatch commands and queries via buses
 * - Handle validation errors and exceptions
 * - Render views with data
 * - Redirect with flash messages
 *
 * NO Business Logic Here!
 * - All business logic is in command/query handlers
 * - Controller only orchestrates (thin controller pattern)
 * - Easy to test handlers independently
 *
 * CQRS in Action:
 * - Write operations use CommandBus (create, update, delete)
 * - Read operations use QueryBus (index, show)
 * - Clear separation of concerns
 *
 * @package App\Controllers\Domain\Cookie
 */
final class CookieController extends BaseController
{
    /**
     * Display paginated list of cookies.
     *
     * GET /cookies
     *
     * @return string
     */
    public function index(): string
    {
        $queryBus = Services::queryBus();

        $pageParam = $this->request->getGet('page');
        $page = is_numeric($pageParam) ? (int) $pageParam : 1;

        $searchParam = $this->request->getGet('search');
        $search = is_string($searchParam) ? $searchParam : null;

        $query = new GetCookiesPaginatedQuery(
            page: $page,
            perPage: 20,
            searchTerm: $search
        );

        $result = $queryBus->ask($query);

        return view('cookies/index', [
            'cookies' => $result['data'],
            'pager' => $result,
            'search' => $search,
        ]);
    }

    /**
     * Display single cookie details.
     *
     * GET /cookies/{id}
     *
     * @param int $id
     * @return string|RedirectResponse
     */
    public function show(int $id): string|RedirectResponse
    {
        $queryBus = Services::queryBus();

        $query = new GetCookieByIdQuery(id: $id);
        $cookie = $queryBus->ask($query);

        if ($cookie === null) {
            return redirect()->to('/cookies')
                ->with('error', 'Cookie not found');
        }

        return view('cookies/show', ['cookie' => $cookie]);
    }

    /**
     * Display create cookie form.
     *
     * GET /cookies/create
     *
     * @return string
     */
    public function create(): string
    {
        return view('cookies/create');
    }

    /**
     * Store a new cookie.
     *
     * POST /cookies
     *
     * @return RedirectResponse
     */
    public function store(): RedirectResponse
    {
        $commandBus = Services::commandBus();

        try {
            $nameParam = $this->request->getPost('name');
            $name = is_string($nameParam) ? $nameParam : '';

            $descriptionParam = $this->request->getPost('description');
            $description = is_string($descriptionParam) ? $descriptionParam : null;

            $priceParam = $this->request->getPost('price');
            $price = is_string($priceParam) ? $priceParam : '';

            $stockParam = $this->request->getPost('stock');
            $stock = is_numeric($stockParam) ? (int) $stockParam : 0;

            $isActiveParam = $this->request->getPost('is_active');
            $isActive = (bool) $isActiveParam;

            $command = new CreateCookieCommand(
                name: $name,
                description: $description,
                price: $price,
                stock: $stock,
                createdBy: Services::actorResolver()->resolve($this->request),
                isActive: $isActive
            );

            $cookieId = $commandBus->dispatch($command);

            return redirect()->to("/cookies/{$cookieId}")
                ->with('success', 'Cookie created successfully');
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withInput()
                ->with('errors', $e->getErrors())
                ->with('error', $e->getMessage());
        } catch (DomainException $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Display edit cookie form.
     *
     * GET /cookies/{id}/edit
     *
     * @param int $id
     * @return string|RedirectResponse
     */
    public function edit(int $id): string|RedirectResponse
    {
        $queryBus = Services::queryBus();

        $query = new GetCookieByIdQuery(id: $id);
        $cookie = $queryBus->ask($query);

        if ($cookie === null) {
            return redirect()->to('/cookies')
                ->with('error', 'Cookie not found');
        }

        return view('cookies/edit', ['cookie' => $cookie]);
    }

    /**
     * Update an existing cookie.
     *
     * POST /cookies/{id}
     *
     * @param int $id
     * @return RedirectResponse
     */
    public function update(int $id): RedirectResponse
    {
        $commandBus = Services::commandBus();

        try {
            $nameParam = $this->request->getPost('name');
            $name = is_string($nameParam) ? $nameParam : '';

            $descriptionParam = $this->request->getPost('description');
            $description = is_string($descriptionParam) ? $descriptionParam : null;

            $priceParam = $this->request->getPost('price');
            $price = is_string($priceParam) ? $priceParam : '';

            $stockParam = $this->request->getPost('stock');
            $stock = is_numeric($stockParam) ? (int) $stockParam : 0;

            $isActiveParam = $this->request->getPost('is_active');
            $isActive = (bool) $isActiveParam;

            $command = new UpdateCookieCommand(
                id: $id,
                name: $name,
                description: $description,
                price: $price,
                stock: $stock,
                isActive: $isActive,
                updatedBy: Services::actorResolver()->resolve($this->request)
            );

            $commandBus->dispatch($command);

            return redirect()->to("/cookies/{$id}")
                ->with('success', 'Cookie updated successfully');
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withInput()
                ->with('errors', $e->getErrors())
                ->with('error', $e->getMessage());
        } catch (DomainException $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', $e->getMessage());
        }
    }

    /**
     * Delete a cookie (soft delete).
     *
     * POST /cookies/{id}/delete
     *
     * @param int $id
     * @return RedirectResponse
     */
    public function delete(int $id): RedirectResponse
    {
        $commandBus = Services::commandBus();

        try {
            $command = new DeleteCookieCommand(
                id: $id,
                deletedBy: Services::actorResolver()->resolve($this->request)
            );
            $commandBus->dispatch($command);

            return redirect()->to('/cookies')
                ->with('success', 'Cookie deleted successfully');
        } catch (DomainException $e) {
            return redirect()->back()
                ->with('error', $e->getMessage());
        }
    }
}
