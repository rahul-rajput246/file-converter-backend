<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ConvertFileRequest extends FormRequest
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
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $supportedFormats = config('file_converter.supported_formats', [
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'ico', 'pdf',
            'mp4', 'webm', 'mov', 'avi', 'mkv',
            'mp3', 'wav', 'ogg', 'aac'
        ]);
        $maxKb = config('file_converter.max_file_size', 102400);

        return [
            'file' => [
                'required',
                'file',
                'max:' . $maxKb,
            ],
            'format' => [
                'required',
                'string',
                'in:' . implode(',', $supportedFormats),
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        $supported = config('file_converter.supported_formats', [
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'ico', 'pdf',
            'mp4', 'webm', 'mov', 'avi', 'mkv',
            'mp3', 'wav', 'ogg', 'aac'
        ]);

        return [
            'file.required' => 'A file is required for conversion.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.max' => 'The file size must not exceed ' . (config('file_converter.max_file_size', 102400) / 1024) . ' MB.',
            'format.required' => 'The target conversion format is required.',
            'format.in' => 'The requested conversion format is not supported. Allowed: ' . strtoupper(implode(', ', $supported)),
        ];
    }

    /**
     * Custom failed validation response format.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'The uploaded file or format is invalid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
