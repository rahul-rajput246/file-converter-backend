<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BatchConvertRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare data for validation.
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
     * Get validation rules.
     */
    public function rules(): array
    {
        $supportedFormats = config('file_converter.supported_formats', [
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp', 'ico', 'pdf',
            'mp4', 'webm', 'mov', 'avi', 'mkv',
            'mp3', 'wav', 'ogg', 'aac', 'txt'
        ]);
        $maxKb = (int) config('file_converter.max_file_size', 102400);
        $maxFiles = (int) config('file_converter.max_batch_files', 10);

        return [
            'files' => [
                'required',
                'array',
                'min:1',
                'max:' . $maxFiles,
            ],
            'files.*' => [
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
     * Validation messages.
     */
    public function messages(): array
    {
        $maxFiles = (int) config('file_converter.max_batch_files', 10);
        $maxMb = (int) (config('file_converter.max_file_size', 102400) / 1024);

        return [
            'files.required' => 'At least one file is required for batch conversion.',
            'files.array' => 'Files must be provided as an array.',
            'files.min' => 'Please upload at least 1 image.',
            'files.max' => "Maximum {$maxFiles} images can be processed at a time.",
            'files.*.required' => 'Uploaded file item cannot be empty.',
            'files.*.file' => 'Each uploaded item must be a valid file.',
            'files.*.max' => "Each file size must not exceed {$maxMb} MB.",
            'format.required' => 'The target format is required.',
            'format.in' => 'The target conversion format is not supported.',
        ];
    }

    /**
     * Custom JSON response on failed validation.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => $validator->errors()->first() ?: 'The uploaded files or format are invalid.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
