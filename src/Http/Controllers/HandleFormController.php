<?php

namespace Dcat\Admin\Http\Controllers;

use Dcat\Admin\Contracts\UploadField as UploadFieldInterface;
use Dcat\Admin\Exception\AdminException;
use Dcat\Admin\Form\Field\Embeds;
use Dcat\Admin\Form\Field\File;
use Dcat\Admin\Form\Field\HasMany;
use Dcat\Admin\Http\JsonResponse;
use Dcat\Admin\Support\FileDeleteSignature;
use Dcat\Admin\Traits\HasUploadedFile;
use Dcat\Admin\Widgets\Form;
use Illuminate\Http\Request;

class HandleFormController
{
    use HasUploadedFile;

    public function handle(Request $request)
    {
        $form = $this->resolveForm($request);

        if (! $form->passesAuthorization()) {
            return $form->failedAuthorization();
        }

        $form->form();

        if ($errors = $form->validate($request)) {
            return $form->validationErrorsResponse($errors);
        }

        $input = $form->sanitize($request->all());

        return $this->sendResponse($form->handle($input));
    }

    public function uploadFile(Request $request)
    {
        $form = $this->resolveForm($request);

        if (! $form->passesAuthorization()) {
            return $form->failedAuthorization();
        }

        $form->form();

        return $this->getField($request, $form)->upload($this->file());
    }

    /**
     * @param  Request  $request
     * @param $form
     * @return File
     */
    protected function getField(Request $request, $form)
    {
        $column = $this->uploader()->upload_column ?: $request->get('_column');

        $field = null;

        if (! $relation = $request->get('_relation')) {
            $field = $form->field($column);
        } else {
            $relation = is_array($relation) ? current($relation) : $relation;

            $relationField = $form->field($relation);

            if ($relationField instanceof HasMany) {
                $field = $relationField->buildNestedForm()->field($column);
            } elseif ($relationField instanceof Embeds) {
                $field = $relationField->field($column);
            }
        }

        if (! $field instanceof UploadFieldInterface) {
            abort(422, sprintf('Invalid upload field [%s].', $column));
        }

        return $field;
    }

    public function destroyFile(Request $request)
    {
        $form = $this->resolveForm($request);

        if (! $form->passesAuthorization()) {
            return $form->failedAuthorization();
        }

        $form->form();

        $field = $this->getField($request, $form);

        if (! $request->filled('key')) {
            abort(422, 'Missing file key.');
        }

        $key = (string) $request->get('key');

        if (! FileDeleteSignature::validate($request->get(FileDeleteSignature::INPUT_NAME), get_class($form), $field->column(), $request->get('_relation'), $key)) {
            return $this->responseDeleteFailed(trans('admin.deny'));
        }

        $field->deleteFile($key);

        return $this->responseDeleted();
    }

    /**
     * @param  Request  $request
     * @return Form
     *
     * @throws AdminException
     */
    protected function resolveForm(Request $request)
    {
        if (! $request->has(Form::REQUEST_NAME)) {
            throw new AdminException('Invalid form request.');
        }

        $formClass = $request->get(Form::REQUEST_NAME);

        if (! class_exists($formClass)) {
            throw new AdminException("Form [{$formClass}] does not exist.");
        }

        /** @var Form $form */
        $form = app($formClass);

        if (! method_exists($form, 'handle')) {
            throw new AdminException("Form method {$formClass}::handle() does not exist.");
        }

        return $form;
    }

    protected function sendResponse($response)
    {
        if ($response instanceof JsonResponse) {
            return $response->send();
        }

        return $response;
    }
}
