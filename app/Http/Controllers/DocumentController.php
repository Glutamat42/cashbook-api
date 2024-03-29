<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Intervention\Image\ImageManager;
use Spatie\PdfToImage\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class DocumentController extends Controller {
    /**
     * @throws Exception
     */
    public function store(Request $request, $entryId) {
        $request['entry_id'] = $entryId; // add entry_id to request so it can be validated
        $validator = Validator::make($request->all(), [
            'document' => 'required',
            'entry_id' => 'required|exists:entries,id', // entry must exist in the database
        ]);

        $validator->sometimes('document', 'file|mimes:avif,webp,jpg,jpeg,png,pdf|max:10240', function ($input) {
            return !is_array($input->document);
        });

        $validator->sometimes('document.*', 'file|mimes:avif,webp,jpg,jpeg,png,pdf|max:10240', function ($input) {
            return is_array($input->document);
        });


        if ($validator->fails()) {
            return response()->json($validator->errors(), 400);
        }

        DB::beginTransaction();
        try {
            $documents = self::processOneOrMultipleFiles($request->document, $entryId);
        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
        DB::commit();


        return response()->json($documents, 201);
    }

    /**
     * @throws Exception
     */
    public static function processOneOrMultipleFiles($files, $entryId): array {
        $documents = [];
        try {
            if (!is_array($files)) {
                $files = [$files];
            }
            foreach ($files as $file) {
                Log::debug('Processing file', ['file' => $file->getClientOriginalName()]);
                $documents = array_merge($documents, self::processFile($file, $entryId)); // process each file
            }

            if (connection_aborted()) {
                Log::warning('Connection aborted while processing the files, cleaning up.', ['files' => $files]);
                throw new Exception('Connection aborted');
            }
        } catch (Exception $e) {
            Log::warning('Something went wrong while processing the files, cleaning up.', ['files' => $files]);
            // Delete any files that were saved before the error occurred
            foreach ($documents as $document) {
                Storage::delete($document['file_path']);
                Storage::delete($document['original_path']);
                Storage::delete($document['thumbnail_path']);
            }

            // throw the exception noticing that something went wrong while processing the files
            throw new Exception('Something went wrong while processing the files');
        }
        return $documents;
    }

    private static function processFile($file, $entryId) {
        // create thumbnails folder if it doesn't exist
        if (!file_exists(storage_path() . '/app/thumbnails')) {
            Log::debug('Creating thumbnails folder');
            mkdir(storage_path() . '/app/thumbnails');
        }
        // create thumbnails folder if it doesn't exist
        if (!file_exists(storage_path() . '/app/originals')) {
            Log::debug('Creating originals folder');
            mkdir(storage_path() . '/app/originals');
        }
        // create thumbnails folder if it doesn't exist
        if (!file_exists(storage_path() . '/app/documents')) {
            Log::debug('Creating documents folder');
            mkdir(storage_path() . '/app/documents');
        }


        $filename = $file->getClientOriginalName();
        $filename_hash = md5(time() . $file->getClientOriginalName()) . '.' . $file->getClientOriginalExtension();
        $originalPath = $file->storeAs('originals', $filename_hash);
        Log::debug('Original file info: ', ['filename' => $filename, 'originalPath' => $originalPath, 'sourceFileSize' => $file->getSize()]);


        if (self::isPDF($file)) {
            Log::info('Processing PDF', ['file' => $file->getClientOriginalName()]);
            $pdf = new Pdf($file);

            // PDFs can have multiple pages, storing every page as a separate image
            for ($i = 1; $i <= $pdf->getNumberOfPages(); $i++) {
                $pdf->setPage($i);
                $pdf->setResolution(300);

                // save to temparary file
                $tmp_file = tempnam(sys_get_temp_dir(), 'jpg');
                $pdf->saveImage($tmp_file);
                $inputImage[] = ImageManager::imagick()->read($tmp_file);
                unlink($tmp_file);
            }
        } elseif (self::isImage($file)) {
            Log::info('Processing image', ['file' => $file->getClientOriginalName()]);
            try {
                $inputImage[] = ImageManager::imagick()->read($file);
            } catch (Exception $e) {
                Log::warning('Failed to open file', ['file' => $file->getClientOriginalName(), 'error' => $e->getMessage()]);
                throw $e;
            }
        } else {
            Log::warning('File type not supported', ['file' => $file]);
            return response()->json(['message' => 'File type not supported'], 400);
        }

        $extension = 'avif'; // Define the extension for the converted images
        $filenameWithoutExtension = pathinfo($filename_hash, PATHINFO_FILENAME);
        $convertImageFilename = $filenameWithoutExtension . '.' . $extension;

        $savedDocuments = [];

        Log::debug('Start thumbnail and document conversion');
        for ($i = 0; $i < count($inputImage); $i++) {
            $page = $inputImage[$i];
            $convertImageFilenameWithPagenumber = $i . '_' . $convertImageFilename;

            $thumbnail = clone $page;
            $document = $page;
            // Create thumbnail for images; if original file was pdf: it is image now
            if ($document->width() > $document->height()) {
                $thumbnail->scaleDown(null, 256);
            } else {
                $thumbnail->scaleDown(256);
            }
            // crop thumbnail to 100x100
            $thumbnail->crop(256, 256, $thumbnail->width() / 2 - 256 / 2, $thumbnail->height() / 2 - 50);

            // scale down document max width/height of 1920px
            $document->scaleDown(2560, 2560);

            $thumbnailPath = 'thumbnails/' . $convertImageFilenameWithPagenumber;
            $thumbnail->toAvif(20)->save(storage_path() . '/app/' . $thumbnailPath);

            $documentPath = 'documents/' . $convertImageFilenameWithPagenumber;
            $document->toAvif(42)->save(storage_path() . '/app/' . $documentPath);

            Log::debug('Finished thumbnail and document conversion, saving to database');
            $document = Document::create([
                'user_id' => Auth::id(),
                'entry_id' => $entryId,
                'original_path' => $originalPath,
                'document_path' => $documentPath,
                'original_filename' => $filename,
                'thumbnail_path' => $thumbnailPath,
            ]);

            $savedDocuments[] = $document;
        }


        return $savedDocuments;
    }

    public function update($document) {
        throw new Exception('Not implemented');
//        // create copy of original category
//        $history_entry = Entry::create(array_merge($category->toArray(), ['id' => null]));
//        // and delete it (keeping it as history)
//        $history_entry->delete();
//        Log::debug('Created and soft deleted history entry', ['id' => $history_entry->id]);
//
//        $category->update(array_merge(
//            $request->all()),
//            ['user_last_modified_id' => Auth::id(), 'category_id' => $category->id]
//        );
    }


    public function getById($entryId) {
        $documents = Document::where('entry_id', $entryId)->get();
        return response()->json($documents);
    }

    public function thumbnail($documentId) {
        Log::debug('Loading thumbnail', ['document_id' => $documentId]);
        return $this->load_and_return_file($documentId, 'thumbnail');
    }

    private function load_and_return_file($documentId, $type) {
        try {
            $document = Document::findOrFail($documentId);
        } catch (Exception $e) {
            Log::info('Document not found', ['document_id' => $documentId]);
            return response('File not found', 404);
        }

        if ($document->thumbnail_path) {
            switch ($type) {
                case 'thumbnail':
                    $path = storage_path('app/' . $document->thumbnail_path);
                    break;
                case 'document':
                    $path = storage_path('app/' . $document->document_path);
                    break;
                case 'original':
                    $path = storage_path('app/' . $document->original_path);
                    break;
                default:
                    return response('File not found', 404);
            }

            if (file_exists($path)) {
                Log::debug('File found', ['path' => $path]);
                $type = mime_content_type($path);

                return response()->download($path, $document->original_filename, ['Content-Type' => $type]);
            } else {
                Log::info('File not found', ['path' => $path]);
            }
        }

        return response($type . ' not found', 404);
    }

    public function index() {
        $documents = Document::all();
        return response()->json($documents);
    }

    public function show($documentId) {
        return $this->load_and_return_file($documentId, 'document');
    }

    public function original($documentId) {
        return $this->load_and_return_file($documentId, 'original');
    }

    public function destroy($documentId) {
        throw new Exception('Not implemented');
        $document = Document::findOrFail($documentId);
        $document->delete();

        return response()->json(['message' => 'Document deleted'], 200);
    }

    private static function isImage($file) {
        return in_array($file->getClientOriginalExtension(), ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp', 'avif']);
    }

    private static function isPDF($file) {
        return strtolower($file->getClientOriginalExtension()) === 'pdf';
    }
}
