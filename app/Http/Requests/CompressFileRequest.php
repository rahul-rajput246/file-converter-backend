<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CompressFileRequest extends FormRequest
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
        if ($this->has('compression_level') && is_string($this->input('compression_level'))) {
            $this->merge([
                'compression_level' => strtolower(trim($this->input('compression_level'))),
            ]);
        }
        if ($this->has('target_size_kb') && is_numeric($this->input('target_size_kb'))) {
            $this->merge([
                'target_size_kb' => (float) $this->input('target_size_kb'),
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
        $supportedFormats = config('file_converter.supported_formats', ['jpg', 'jpeg', 'png', 'webp']);
        $supportedMimes = config('file_converter.supported_mimes', ['image/jpeg', 'image/png', 'image/webp']);
        $allowedLevels = array_keys(config('file_converter.compression_levels', ['low' => [], 'medium' => [], 'high' => []]));
        $maxKb = config('file_converter.max_file_size', 102400);

        return [
            'file' => [
                'required',
                'file',
                'max:' . $maxKb,
                'mimes:' . implode(',', $supportedFormats),
                'mimetypes:' . implode(',', $supportedMimes),
            ],
            'compression_level' => [
                'nullable',
                'string',
                'in:' . implode(',', array_merge($allowedLevels, ['custom', 'target'])),
            ],
            'target_size_kb' => [
                'nullable',
                'numeric',
                'min:1',
                'max:' . $maxKb,
            ],
            'target_size' => [
                'nullable',
                'numeric',
                'min:1',
                'max:' . $maxKb,
            ],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'file.required' => 'An image file is required.',
            'file.file' => 'The uploaded item must be a valid file.',
            'file.max' => 'The file size must not exceed ' . (config('file_converter.max_file_size', 102400) / 1024) . ' MB.',
            'file.mimes' => 'The file must be a supported image (JPG, PNG, WEBP, GIF, AVIF, BMP, ICO) or PDF document.',
            'file.mimetypes' => 'The file MIME type is unsupported.',
            'compression_level.required_without' => 'Please specify a compression level or a target file size in KB.',
            'compression_level.in' => 'The compression level must be one of: ' . implode(', ', array_keys(config('file_converter.compression_levels', ['low' => [], 'medium' => [], 'high' => []]))),
            'target_size_kb.required_without' => 'Please specify a target file size in KB or a compression level.',
            'target_size_kb.numeric' => 'The target file size must be a number.',
            'target_size_kb.min' => 'The target file size must be at least 1 KB.',
            'target_size_kb.max' => 'The target file size cannot exceed the maximum upload limit.',
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
                'message' => 'The uploaded file or compression level is invalid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
