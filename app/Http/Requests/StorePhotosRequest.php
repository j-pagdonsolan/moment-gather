<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StorePhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Public endpoint: no authentication required. Eligibility
        // (active event, upload_enabled) is enforced in the controller.
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photos'   => ['required', 'array', 'max:'.config('uploads.max_files')],
            'photos.*' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.config('uploads.max_file_kb'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photos.required'   => 'Please select at least one photo to upload.',
            'photos.array'      => 'Please select at least one photo to upload.',
            'photos.max'        => 'You can upload up to :max photos at a time.',
            'photos.*.image'    => 'One of the selected files is not a valid image.',
            'photos.*.mimes'    => 'Photos must be JPG, PNG, or WEBP files.',
            'photos.*.max'      => 'Each photo must be 20 MB or smaller.',
            'photos.*.required' => 'One of the selected files could not be read.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('Upload validation failed', [
            'event_slug'  => $this->route('slug'),
            'file_count'  => is_array($this->file('photos')) ? count($this->file('photos')) : 0,
            'first_error' => array_key_first($validator->errors()->toArray()),
        ]);

        parent::failedValidation($validator);
    }
}
