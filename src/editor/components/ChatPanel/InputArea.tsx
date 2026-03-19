/**
 * InputArea — Chat message input with auto-growing textarea.
 *
 * Layout inspired by modern AI chat UIs:
 * - Rounded card container with warm background
 * - Textarea at top
 * - Bottom toolbar: [+] button (image/figma popover) | model pill | send/stop
 */

import {
  useState,
  useRef,
  useCallback,
  useEffect,
  type KeyboardEvent,
  type ChangeEvent,
} from 'react';
import type { Attachment, FigmaAttachment, SelectedSection } from '@/editor/types';
import { uploadImage, type UploadedImage } from '@/editor/api/wordpress';
import { useChat } from '@/editor/hooks/useChat';
import { useModelSelection } from '@/editor/hooks/useChat';
import { useEditorStore } from '@/editor/store/editorStore';
import { FigmaModal } from '@/editor/components/Modals/FigmaModal';

const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;
const MAX_FILE_COUNT = 4;
const ACCEPTED_TYPES = new Set(['image/png', 'image/jpeg', 'image/webp', 'image/gif']);

export function InputArea() {
  const [text, setText] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const { sendMessage, isStreaming, stopStreaming } = useChat();
  const { selectedModel, availableModels, setModel } = useModelSelection();
  const selectedSection = useEditorStore((s) => s.selectedSection);
  const setSelectedSection = useEditorStore((s) => s.setSelectedSection);

  const [pendingImages, setPendingImages] = useState<UploadedImage[]>([]);
  const [isUploading, setIsUploading] = useState(false);
  const [uploadingCount, setUploadingCount] = useState(0);
  const [figmaAttachment, setFigmaAttachment] = useState<FigmaAttachment | null>(null);
  const [showFigmaModal, setShowFigmaModal] = useState(false);
  const [showFigmaConnect, setShowFigmaConnect] = useState(false);
  const [showPlusMenu, setShowPlusMenu] = useState(false);

  const plusMenuRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const canSend =
    (text.trim().length > 0 || pendingImages.length > 0 || !!figmaAttachment) &&
    !isStreaming;

  // Close plus menu on outside click
  useEffect(() => {
    if (!showPlusMenu) return;
    function handle(e: MouseEvent) {
      if (plusMenuRef.current && !plusMenuRef.current.contains(e.target as Node)) {
        setShowPlusMenu(false);
      }
    }
    document.addEventListener('mousedown', handle);
    return () => document.removeEventListener('mousedown', handle);
  }, [showPlusMenu]);

  // ---------------------------------------------------------------------------
  // Image handlers
  // ---------------------------------------------------------------------------

  const handleImagesSelected = useCallback(async (files: File[]) => {
    const remaining = MAX_FILE_COUNT - pendingImages.length;
    if (remaining <= 0) return;
    const filesToUpload = files.slice(0, remaining);
    setIsUploading(true);
    setUploadingCount(filesToUpload.length);
    try {
      const results = await Promise.all(filesToUpload.map((file) => uploadImage(file)));
      setPendingImages((prev) => [...prev, ...results]);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Image upload failed.';
      useEditorStore.getState().clearError();
      useEditorStore.setState({ error: msg });
    } finally {
      setIsUploading(false);
      setUploadingCount(0);
    }
  }, [pendingImages.length]);

  const handleFileInput = useCallback(
    (event: React.ChangeEvent<HTMLInputElement>) => {
      const fileList = event.target.files;
      if (!fileList || fileList.length === 0) return;
      const valid: File[] = [];
      for (let i = 0; i < fileList.length; i++) {
        const f = fileList[i];
        if (!ACCEPTED_TYPES.has(f.type) || f.size > MAX_FILE_SIZE_BYTES) continue;
        if (valid.length >= MAX_FILE_COUNT) break;
        valid.push(f);
      }
      if (fileInputRef.current) fileInputRef.current.value = '';
      if (valid.length > 0) handleImagesSelected(valid);
    },
    [handleImagesSelected],
  );

  const removePendingImage = useCallback((id: string) => {
    setPendingImages((prev) => prev.filter((img) => img.id !== id));
  }, []);

  // ---------------------------------------------------------------------------
  // Figma handlers
  // ---------------------------------------------------------------------------

  const handleFigmaAttach = useCallback((attachment: FigmaAttachment) => {
    setFigmaAttachment(attachment);
    setShowFigmaModal(false);
  }, []);

  const removeFigmaAttachment = useCallback(() => {
    setFigmaAttachment(null);
  }, []);

  // ---------------------------------------------------------------------------
  // Send / keyboard
  // ---------------------------------------------------------------------------

  const handleSend = useCallback(async () => {
    const trimmed = text.trim();
    if ((!trimmed && pendingImages.length === 0 && !figmaAttachment) || isStreaming) return;

    const attachments: Attachment[] = [];
    for (const img of pendingImages) {
      attachments.push({
        type: 'image' as const,
        id: img.id,
        url: img.url,
        mediaType: img.mediaType,
        thumbnailUrl: img.thumbnailUrl,
      });
    }
    if (figmaAttachment) attachments.push(figmaAttachment);

    // Prepend section context if a section is selected
    let finalMessage = trimmed;
    if (selectedSection) {
      finalMessage = `[EDIT SECTION: "${selectedSection.label}" (${selectedSection.selector})]\n${selectedSection.outerHtmlSnippet}\n\n[USER REQUEST]: ${trimmed}`;
    }

    setText('');
    setPendingImages([]);
    setFigmaAttachment(null);
    setSelectedSection(null);
    if (textareaRef.current) textareaRef.current.style.height = 'auto';

    await sendMessage(finalMessage, attachments.length > 0 ? attachments : undefined);
  }, [text, pendingImages, figmaAttachment, isStreaming, sendMessage, selectedSection, setSelectedSection]);

  const handleKeyDown = useCallback(
    (e: KeyboardEvent<HTMLTextAreaElement>) => {
      if (e.key === 'Enter' && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
        e.preventDefault();
        handleSend();
      }
    },
    [handleSend],
  );

  const handleChange = useCallback((e: ChangeEvent<HTMLTextAreaElement>) => {
    setText(e.target.value);
    const el = e.target;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
  }, []);

  const hasFigma = !!window.wpvibeData?.hasFigma;

  return (
    <>
      <div className="vb-flex-shrink-0 vb-px-3 vb-pb-3 vb-pt-1">
        {/* Unified card — textarea + inline toolbar */}
        <div className="vb-rounded-2xl vb-border vb-border-slate-200/40 vb-bg-white vb-shadow-[0_1px_3px_rgba(0,0,0,0.04)] vb-px-3.5 vb-py-3 vb-transition-all focus-within:vb-shadow-[0_2px_8px_rgba(0,0,0,0.06)] focus-within:vb-border-slate-300">

          {/* Pending attachments + upload placeholders */}
          {(pendingImages.length > 0 || figmaAttachment || isUploading) && (
            <div className="vb-flex vb-gap-2 vb-mb-2 vb-flex-wrap">
              {pendingImages.map((img) => (
                <div
                  key={img.id}
                  className="vb-relative vb-w-14 vb-h-14 vb-rounded-lg vb-overflow-hidden vb-border vb-border-slate-200 vb-group"
                >
                  <img src={img.thumbnailUrl} alt="" className="vb-w-full vb-h-full vb-object-cover" />
                  <button
                    type="button"
                    onClick={() => removePendingImage(img.id)}
                    className="vb-absolute vb-top-0 vb-right-0 vb-w-4 vb-h-4 vb-flex vb-items-center vb-justify-center vb-bg-black/60 vb-text-white vb-text-[10px] vb-rounded-bl-md vb-opacity-0 group-hover:vb-opacity-100 vb-transition-opacity"
                    aria-label="Remove image"
                  >
                    &times;
                  </button>
                </div>
              ))}
              {/* Upload placeholder skeletons */}
              {isUploading && Array.from({ length: uploadingCount }).map((_, i) => (
                <div
                  key={`uploading-${i}`}
                  className="vb-w-14 vb-h-14 vb-rounded-lg vb-border vb-border-slate-200 vb-bg-slate-100 vb-flex vb-items-center vb-justify-center vb-animate-pulse"
                >
                  <svg className="vb-animate-spin vb-h-5 vb-w-5 vb-text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle className="vb-opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                    <path className="vb-opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                </div>
              ))}
              {figmaAttachment && (
                <div className="vb-flex vb-items-center vb-gap-1.5 vb-px-2.5 vb-py-1 vb-rounded-lg vb-border vb-border-indigo-200 vb-bg-indigo-50 vb-text-xs vb-text-indigo-700">
                  <span>Figma: {figmaAttachment.context.frameName}</span>
                  <button
                    type="button"
                    onClick={removeFigmaAttachment}
                    className="vb-text-indigo-400 hover:vb-text-indigo-600 vb-text-xs"
                    aria-label="Remove Figma attachment"
                  >
                    &times;
                  </button>
                </div>
              )}
            </div>
          )}

          {/* Selected section chip */}
          {selectedSection && (
            <div className="vb-flex vb-items-center vb-gap-1.5 vb-mb-2 vb-px-0.5">
              <div className="vb-flex vb-items-center vb-gap-1.5 vb-px-2.5 vb-py-1 vb-rounded-lg vb-bg-indigo-50 vb-border vb-border-indigo-200 vb-text-xs vb-text-indigo-700">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z" />
                </svg>
                <span className="vb-font-medium">Editing:</span>
                <span className="vb-font-mono">{selectedSection.label}</span>
                <button
                  type="button"
                  onClick={() => setSelectedSection(null)}
                  className="vb-text-indigo-400 hover:vb-text-indigo-600 vb-ml-0.5"
                  aria-label="Clear section selection"
                >
                  &times;
                </button>
              </div>
            </div>
          )}

          {/* Textarea — full width, no visual separation from toolbar */}
          <textarea
            ref={textareaRef}
            value={text}
            onChange={handleChange}
            onKeyDown={handleKeyDown}
            placeholder="Describe the theme you want to create..."
            rows={3}
            disabled={isStreaming}
            className="vb-w-full vb-resize-none vb-bg-transparent vb-text-sm vb-text-slate-800 vb-leading-relaxed vb-placeholder-slate-400 disabled:vb-opacity-50 disabled:vb-cursor-not-allowed"
            style={{ maxHeight: '200px', outline: 'none', boxShadow: 'none', border: 'none' }}
          />

          {/* Inline toolbar — sits at the bottom inside the card */}
          <div className="vb-flex vb-items-center vb-justify-between vb-mt-2">
            {/* Left: + button with popover */}
            <div className="vb-flex vb-items-center vb-gap-1.5">
              <div className="vb-relative" ref={plusMenuRef}>
                <button
                  type="button"
                  onClick={() => setShowPlusMenu(!showPlusMenu)}
                  disabled={isStreaming}
                  className={[
                    'vb-flex vb-items-center vb-justify-center vb-w-7 vb-h-7 vb-rounded-full vb-border vb-transition-colors',
                    isStreaming
                      ? 'vb-border-slate-200 vb-text-slate-300 vb-cursor-not-allowed'
                      : showPlusMenu
                        ? 'vb-border-slate-400 vb-bg-slate-200 vb-text-slate-600'
                        : 'vb-border-slate-300 vb-text-slate-500 hover:vb-bg-slate-100 hover:vb-border-slate-400',
                  ].join(' ')}
                  aria-label="Attach"
                  title="Attach image or Figma frame"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19" />
                    <line x1="5" y1="12" x2="19" y2="12" />
                  </svg>
                </button>

                {/* Popover menu */}
                {showPlusMenu && (
                  <div className="vb-absolute vb-bottom-full vb-left-0 vb-mb-2 vb-w-48 vb-bg-white vb-rounded-xl vb-shadow-lg vb-border vb-border-slate-200 vb-py-1.5 vb-z-50">
                    <button
                      type="button"
                      onClick={() => {
                        setShowPlusMenu(false);
                        fileInputRef.current?.click();
                      }}
                      disabled={isUploading}
                      className="vb-flex vb-items-center vb-gap-2.5 vb-w-full vb-px-3.5 vb-py-2 vb-text-sm vb-text-slate-700 hover:vb-bg-slate-50 vb-transition-colors disabled:vb-opacity-50"
                    >
                      {isUploading ? (
                        <svg className="vb-animate-spin vb-h-4 vb-w-4 vb-text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                          <circle className="vb-opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                          <path className="vb-opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                      ) : (
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="vb-text-slate-400">
                          <rect x="3" y="3" width="18" height="18" rx="2" ry="2" />
                          <circle cx="8.5" cy="8.5" r="1.5" />
                          <polyline points="21 15 16 10 5 21" />
                        </svg>
                      )}
                      <span>{isUploading ? 'Uploading...' : 'Attach image'}</span>
                    </button>

                    <button
                      type="button"
                      onClick={() => {
                        setShowPlusMenu(false);
                        if (hasFigma) {
                          setShowFigmaModal(true);
                        } else {
                          setShowFigmaConnect(true);
                        }
                      }}
                      className="vb-flex vb-items-center vb-gap-2.5 vb-w-full vb-px-3.5 vb-py-2 vb-text-sm vb-text-slate-700 hover:vb-bg-slate-50 vb-transition-colors"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="vb-text-slate-400">
                        <path d="M5 5.5A3.5 3.5 0 018.5 2H12v7H8.5A3.5 3.5 0 015 5.5z" />
                        <path d="M12 2h3.5a3.5 3.5 0 110 7H12V2z" />
                        <path d="M12 12.5a3.5 3.5 0 117 0 3.5 3.5 0 11-7 0z" />
                        <path d="M5 19.5A3.5 3.5 0 018.5 16H12v3.5a3.5 3.5 0 11-7 0z" />
                        <path d="M5 12.5A3.5 3.5 0 018.5 9H12v7H8.5A3.5 3.5 0 015 12.5z" />
                      </svg>
                      <div className="vb-flex vb-flex-col">
                        <span>Attach Figma frame</span>
                        {!hasFigma && (
                          <span className="vb-text-[10px] vb-text-slate-400">Not connected</span>
                        )}
                      </div>
                    </button>
                  </div>
                )}
              </div>

              {/* Model selector pill — inline with + button */}
              {availableModels.length > 0 && (
                <select
                  value={selectedModel}
                  onChange={(e) => setModel(e.target.value)}
                  className="vb-appearance-none vb-bg-transparent vb-text-slate-500 vb-text-[11px] vb-font-medium vb-rounded-full vb-border vb-border-slate-200 vb-pl-2.5 vb-pr-5 vb-py-1 vb-outline-none vb-cursor-pointer vb-transition-colors hover:vb-bg-slate-100 hover:vb-border-slate-300 focus:vb-border-slate-300"
                  style={{
                    backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E")`,
                    backgroundRepeat: 'no-repeat',
                    backgroundPosition: 'right 5px center',
                  }}
                >
                  {availableModels.map((model) => (
                    <option key={model.id} value={model.id}>
                      {model.name}
                    </option>
                  ))}
                </select>
              )}
            </div>

            {/* Right: send/stop + shortcut hint */}
            <div className="vb-flex vb-items-center vb-gap-2">
              {isStreaming ? (
                <button
                  type="button"
                  onClick={stopStreaming}
                  className="vb-flex vb-items-center vb-justify-center vb-w-7 vb-h-7 vb-rounded-full vb-bg-slate-800 vb-text-white vb-transition-colors hover:vb-bg-red-600 active:vb-bg-red-700 vb-flex-shrink-0"
                  aria-label="Stop generating"
                  title="Stop generating"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="6" width="12" height="12" rx="1" />
                  </svg>
                </button>
              ) : (
                <button
                  type="button"
                  onClick={handleSend}
                  disabled={!canSend}
                  className={[
                    'vb-flex vb-items-center vb-justify-center vb-w-7 vb-h-7 vb-rounded-full vb-transition-colors vb-flex-shrink-0',
                    canSend
                      ? 'vb-bg-slate-800 vb-text-white hover:vb-bg-slate-700 active:vb-bg-slate-900'
                      : 'vb-bg-slate-200 vb-text-slate-400 vb-cursor-not-allowed',
                  ].join(' ')}
                  aria-label="Send message"
                  title="Send message (Enter)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                    <line x1="12" y1="19" x2="12" y2="5" />
                    <polyline points="5 12 12 5 19 12" />
                  </svg>
                </button>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* Hidden file input */}
      <input
        ref={fileInputRef}
        type="file"
        accept="image/png,image/jpeg,image/webp,image/gif"
        multiple
        onChange={handleFileInput}
        className="vb-hidden"
        aria-hidden="true"
        tabIndex={-1}
      />

      <FigmaModal
        isOpen={showFigmaModal}
        onClose={() => setShowFigmaModal(false)}
        onAttach={handleFigmaAttach}
      />

      {/* Figma Connect dialog — shown when Figma is not configured */}
      {showFigmaConnect && (
        <div
          className="vb-fixed vb-inset-0 vb-z-[100000] vb-flex vb-items-center vb-justify-center"
          onClick={() => setShowFigmaConnect(false)}
        >
          <div className="vb-absolute vb-inset-0 vb-bg-black/50" />
          <div
            className="vb-relative vb-bg-white vb-rounded-xl vb-shadow-2xl vb-w-full vb-max-w-md vb-mx-4"
            onClick={(e) => e.stopPropagation()}
          >
            {/* Header */}
            <div className="vb-flex vb-items-center vb-justify-between vb-px-6 vb-py-4 vb-border-b vb-border-slate-200">
              <div className="vb-flex vb-items-center vb-gap-2.5">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="vb-text-indigo-500">
                  <path d="M5 5.5A3.5 3.5 0 018.5 2H12v7H8.5A3.5 3.5 0 015 5.5z" />
                  <path d="M12 2h3.5a3.5 3.5 0 110 7H12V2z" />
                  <path d="M12 12.5a3.5 3.5 0 117 0 3.5 3.5 0 11-7 0z" />
                  <path d="M5 19.5A3.5 3.5 0 018.5 16H12v3.5a3.5 3.5 0 11-7 0z" />
                  <path d="M5 12.5A3.5 3.5 0 018.5 9H12v7H8.5A3.5 3.5 0 015 12.5z" />
                </svg>
                <h2 className="vb-text-lg vb-font-semibold vb-text-slate-800">Connect Figma</h2>
              </div>
              <button
                type="button"
                onClick={() => setShowFigmaConnect(false)}
                className="vb-text-slate-400 hover:vb-text-slate-600 vb-transition-colors vb-p-1 vb-rounded"
                aria-label="Close"
              >
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <line x1="18" y1="6" x2="6" y2="18" />
                  <line x1="6" y1="6" x2="18" y2="18" />
                </svg>
              </button>
            </div>

            {/* Body */}
            <div className="vb-px-6 vb-py-5 vb-space-y-4">
              <p className="vb-text-sm vb-text-slate-600 vb-leading-relaxed">
                Connect your Figma account to import designs directly into your theme. You can set this up in the plugin settings.
              </p>

              <div className="vb-space-y-2">
                {/* Option 1: Settings page */}
                <a
                  href={`${window.wpvibeData?.adminUrl ?? ''}admin.php?page=wpvibe-settings`}
                  className="vb-flex vb-items-center vb-gap-3 vb-w-full vb-px-4 vb-py-3 vb-rounded-lg vb-border vb-border-slate-200 vb-text-sm vb-text-slate-700 hover:vb-bg-slate-50 hover:vb-border-slate-300 vb-transition-colors vb-no-underline"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="vb-text-slate-400 vb-flex-shrink-0">
                    <circle cx="12" cy="12" r="3" />
                    <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.32 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z" />
                  </svg>
                  <div>
                    <div className="vb-font-medium">Open Settings</div>
                    <div className="vb-text-xs vb-text-slate-400">Add your Figma Personal Access Token</div>
                  </div>
                </a>

                {/* Option 2: How to get token */}
                <div className="vb-px-4 vb-py-3 vb-rounded-lg vb-bg-slate-50 vb-border vb-border-slate-100">
                  <p className="vb-text-xs vb-font-medium vb-text-slate-600 vb-mb-1.5">How to get a Figma token:</p>
                  <ol className="vb-text-xs vb-text-slate-500 vb-space-y-1 vb-pl-4" style={{ listStyleType: 'decimal' }}>
                    <li>Go to Figma &rarr; Settings &rarr; Account</li>
                    <li>Scroll to Personal Access Tokens</li>
                    <li>Generate a new token and copy it</li>
                    <li>Paste it in WP Vibe Settings</li>
                  </ol>
                </div>
              </div>
            </div>

            {/* Footer */}
            <div className="vb-px-6 vb-py-4 vb-border-t vb-border-slate-200 vb-flex vb-justify-end">
              <button
                type="button"
                onClick={() => setShowFigmaConnect(false)}
                className="vb-rounded-lg vb-border vb-border-slate-300 vb-bg-white vb-px-4 vb-py-2 vb-text-sm vb-font-medium vb-text-slate-700 vb-transition-colors hover:vb-bg-slate-50"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
