<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\Admin\StoreFormFieldRequest;
use App\Http\Requests\Forms\Admin\UpdateFormFieldRequest;
use App\Http\Resources\Forms\FormFieldResource;
use App\Http\Routing\FormRouteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The field editor for every form owner — an event's registration form, a
 * service type's request form, and whatever owns a form next. Routes are
 * registered per owner by App\Http\Routing\FormFieldRoutes, which is also
 * what tells this controller which owner and purpose it is editing.
 *
 * Deliberately not an AdminResourceController subclass: nothing here
 * varies by owner except configuration, and owner-specific rules (an
 * event's `has_registration_form` flag, its external-form lock) live on
 * the owner's HasForms hooks.
 */
class AdminFormFieldController extends Controller
{
    /** Every field, active or not: this is the editor, not the form. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $form = FormRouteContext::fromRequest($request)->form();

        return FormFieldResource::collection($form?->fields ?? collect());
    }

    public function store(StoreFormFieldRequest $request): JsonResponse
    {
        $context = FormRouteContext::fromRequest($request);

        $field = DB::transaction(
            fn () => $context->formOrCreate()->fields()->create($request->validated())
        );

        return (new FormFieldResource($field))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateFormFieldRequest $request): JsonResponse
    {
        $request->field()->update($request->validated());

        return response()->json(['message' => __('api.forms.field_updated')]);
    }

    /** Answers already given keep their snapshot; see FormAnswer. */
    public function destroy(Request $request): JsonResponse
    {
        FormRouteContext::fromRequest($request)->field($request)->delete();

        return response()->json(['message' => __('api.forms.field_deleted')]);
    }
}
