<?php

namespace App\Http\Routing;

use App\Domain\Forms\Contracts\HasForms;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;

/**
 * The owner, purpose and form a FormFieldRoutes route is editing, resolved
 * once per request and shared by the Form Request and the controller.
 *
 * Every field lookup goes through the owner's form for this purpose. That
 * scope is what stops a field being reached through another owner — or
 * through an owner of another type that happens to share its numeric id.
 */
final class FormRouteContext
{
    private ?Form $form = null;

    private bool $formLoaded = false;

    private function __construct(
        public readonly Model&HasForms $owner,
        public readonly FormPurpose $purpose,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $route = $request->route();

        if (! $route instanceof Route || ! is_array($config = $route->getAction(FormFieldRoutes::ACTION_KEY))) {
            throw new LogicException('This route was not registered through '.FormFieldRoutes::class.'.');
        }

        // Kept on the request, not the route: one Route object serves every
        // request that matches it, each with its own parameters.
        if (! $request->attributes->has(self::class)) {
            $request->attributes->set(self::class, new self(
                self::resolveOwner($config['owner'], $route->parameter($config['param'])),
                FormPurpose::from($config['purpose']),
            ));
        }

        return $request->attributes->get(self::class);
    }

    /** The owner's form for this purpose, or null while it has none. */
    public function form(): ?Form
    {
        if (! $this->formLoaded) {
            $this->form = $this->owner->form($this->purpose)->first()?->setRelation('formable', $this->owner);
            $this->formLoaded = true;
        }

        return $this->form;
    }

    public function formOrCreate(): Form
    {
        $this->form = $this->owner->formOrCreate($this->purpose);
        $this->formLoaded = true;

        return $this->form;
    }

    /** The `{field}` on this route, found only within this owner's form. */
    public function field(Request $request): FormField
    {
        $id = $request->route('field');
        $field = $this->form()?->fields()->whereKey($id)->first();

        if ($field === null) {
            throw (new ModelNotFoundException)->setModel(FormField::class, [$id]);
        }

        return $field->setRelation('form', $this->form());
    }

    private static function resolveOwner(string $alias, mixed $value): Model&HasForms
    {
        $class = Relation::getMorphedModel($alias)
            ?? throw new LogicException("Morph alias \"{$alias}\" is not mapped.");

        $owner = $value instanceof $class ? $value : (new $class)->resolveRouteBinding($value);

        if ($owner === null) {
            throw (new ModelNotFoundException)->setModel($class, [$value]);
        }

        return $owner;
    }
}
