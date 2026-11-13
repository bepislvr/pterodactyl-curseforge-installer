<?php

namespace Pterodactyl\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Test stub: the panel's version checks for a root admin. */
abstract class AdminFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
