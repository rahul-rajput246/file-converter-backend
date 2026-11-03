<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CropFileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('format') && is_string($this->input('format'))) {
            $this->merge([
                'format' => strtolower(trim($this->input('format'))),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $maxKb = config('file_converter.max_file_size', 102400);

        return [
            'file' => [
                'required',
                'file',
                'max:' . $maxKb,
                'mimes:jpg,jpeg,png,webp,gif,bmp,avif',
            ],
            'x' => 'required|numeric|min:0',
            'y' => 'required|numeric|min:0',
            'width' => 'required|numeric|min:1',
            'height' => 'required|numeric|min:1',
            'format' => 'nullable|string|in:jpg,jpeg,png,webp,gif,bmp',
            'quality' => 'nullable|integer|min:10|max:100',
        ];
    }

    /**
     * Custom error messages.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Please provide an image file to crop.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.max' => 'The file size cannot exceed ' . (config('file_converter.max_file_size', 102400) / 1024) . 'MB.',
            'file.mimes' => 'Only image files (JPG, PNG, WEBP, GIF, BMP, AVIF) can be cropped.',
            'x.required' => 'Crop coordinate X is required.',
            'y.required' => 'Crop coordinate Y is required.',
            'width.required' => 'Crop width is required.',
            'height.required' => 'Crop height is required.',
        ];
    }

    /**
     * Handle failed validation for API JSON response.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation error',
            'errors' => $validator->errors(),
        ], 422));
    }
}

