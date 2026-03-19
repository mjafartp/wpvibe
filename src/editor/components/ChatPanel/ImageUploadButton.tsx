/**
 * ImageUploadButton — Allows users to attach reference images to chat messages.
 *
 * Validates file type (PNG, JPEG, WebP, GIF), enforces a 10 MB per-file limit,
 * and caps selection at 4 images. When uploading is in progress, the paperclip
 * icon is replaced with a spinner.
 */

import { useCallback, useRef } from 'react';

const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
const MAX_FILE_COUNT = 4;
const ACCEPTED_TYPES = new Set([
  'image/png',
  'image/jpeg',
  'image/webp',
  'image/gif',
]);

interface ImageUploadButtonProps {
  onImagesSelected: (files: File[]) => void;
  disabled?: boolean;
  isUploading?: boolean;
}

export function ImageUploadButton({
  onImagesSelected,
  disabled = false,
  isUploading = false,
}: ImageUploadButtonProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleClick = useCallback(() => {
    if (disabled || isUploading) return;
    fileInputRef.current?.click();
  }, [disabled, isUploading]);

  const handleFileChange = useCallback(
    (event: React.ChangeEvent<HTMLInputElement>) => {
      const fileList = event.target.files;
      if (!fileList || fileList.length === 0) return;

      const validFiles: File[] = [];

      for (let i = 0; i < fileList.length; i++) {
        const file = fileList[i];

        if (!ACCEPTED_TYPES.has(file.type)) continue;
        if (file.size > MAX_FILE_SIZE_BYTES) continue;
        if (validFiles.length >= MAX_FILE_COUNT) break;

        validFiles.push(file);
      }

      // Reset input so the same file(s) can be re-selected if needed
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }

      if (validFiles.length > 0) {
        onImagesSelected(validFiles);
      }
    },
    [onImagesSelected],
  );

  const isInactive = disabled || isUploading;

  const buttonClassName = isInactive
    ? 'vb-flex vb-items-center vb-justify-center vb-w-8 vb-h-8 vb-rounded-lg vb-border vb-border-slate-200 vb-bg-white vb-text-slate-300 vb-cursor-not-allowed vb-transition-colors'
    : 'vb-flex vb-items-center vb-justify-center vb-w-8 vb-h-8 vb-rounded-lg vb-border vb-border-slate-200 vb-bg-white vb-text-slate-500 vb-transition-colors hover:vb-bg-slate-50 hover:vb-text-indigo-600 hover:vb-border-indigo-200';

  return (
    <>
      <button
        type="button"
        disabled={isInactive}
        onClick={handleClick}
        title={isUploading ? 'Uploading...' : 'Attach image'}
        className={buttonClassName}
        aria-label={isUploading ? 'Uploading image' : 'Upload image'}
      >
        {isUploading ? <Spinner /> : <PaperclipIcon />}
      </button>

      <input
        ref={fileInputRef}
        type="file"
        accept="image/png,image/jpeg,image/webp,image/gif"
        multiple
        onChange={handleFileChange}
        className="vb-hidden"
        aria-hidden="true"
        tabIndex={-1}
      />
    </>
  );
}

// ---------------------------------------------------------------------------
// Icons
// ---------------------------------------------------------------------------

function PaperclipIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48" />
    </svg>
  );
}

function Spinner() {
  return (
    <svg
      className="vb-animate-spin vb-h-3.5 vb-w-3.5"
      xmlns="http://www.w3.org/2000/svg"
      fill="none"
      viewBox="0 0 24 24"
    >
      <circle
        className="vb-opacity-25"
        cx="12"
        cy="12"
        r="10"
        stroke="currentColor"
        strokeWidth="4"
      />
      <path
        className="vb-opacity-75"
        fill="currentColor"
        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
      />
    </svg>
  );
}
