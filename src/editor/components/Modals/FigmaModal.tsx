/**
 * FigmaModal -- Modal dialog for selecting Figma frames to attach
 * to a chat message.
 *
 * Displays a URL input for a Figma file link, fetches available frames,
 * and lets the user select which frames to attach as context.
 */

import { useState, useCallback, useEffect } from 'react';

import type { FigmaAttachment, FigmaFrame } from '@/editor/types';
import { fetchFigmaFrames, fetchFigmaContext } from '@/editor/api/wordpress';

interface FigmaModalProps {
  isOpen: boolean;
  onClose: () => void;
  onAttach: (attachment: FigmaAttachment) => void;
}

export function FigmaModal({ isOpen, onClose, onAttach }: FigmaModalProps) {
  const [fileUrl, setFileUrl] = useState('');
  const [frames, setFrames] = useState<FigmaFrame[]>([]);
  const [selectedFrameIds, setSelectedFrameIds] = useState<Set<string>>(
    new Set(),
  );
  const [isLoadingFrames, setIsLoadingFrames] = useState(false);
  const [isLoadingContext, setIsLoadingContext] = useState(false);
  const [error, setError] = useState('');

  // Reset all state when the modal closes.
  useEffect(() => {
    if (!isOpen) {
      setFileUrl('');
      setFrames([]);
      setSelectedFrameIds(new Set());
      setIsLoadingFrames(false);
      setIsLoadingContext(false);
      setError('');
    }
  }, [isOpen]);

  // Close on Escape key.
  useEffect(() => {
    if (!isOpen) return;

    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        onClose();
      }
    }

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  const handleFetchFrames = useCallback(async () => {
    if (!fileUrl.trim()) return;

    setError('');
    setFrames([]);
    setSelectedFrameIds(new Set());
    setIsLoadingFrames(true);

    try {
      const data = await fetchFigmaFrames(fileUrl);
      setFrames(data.frames);
    } catch (err) {
      const message =
        err instanceof Error ? err.message : 'Failed to fetch Figma frames.';
      setError(message);
    } finally {
      setIsLoadingFrames(false);
    }
  }, [fileUrl]);

  const handleToggleFrame = useCallback((frameId: string) => {
    setSelectedFrameIds((prev) => {
      const next = new Set(prev);
      if (next.has(frameId)) {
        next.delete(frameId);
      } else {
        next.add(frameId);
      }
      return next;
    });
  }, []);

  const handleAttach = useCallback(async () => {
    if (selectedFrameIds.size === 0) return;

    setError('');
    setIsLoadingContext(true);

    try {
      const context = await fetchFigmaContext(fileUrl, [...selectedFrameIds]);
      onAttach({ type: 'figma', context });
      onClose();
    } catch (err) {
      const message =
        err instanceof Error ? err.message : 'Failed to fetch Figma context.';
      setError(message);
    } finally {
      setIsLoadingContext(false);
    }
  }, [fileUrl, selectedFrameIds, onAttach, onClose]);

  if (!isOpen) return null;

  return (
    <div
      className="vb-fixed vb-inset-0 vb-z-[100000] vb-flex vb-items-center vb-justify-center"
      onClick={onClose}
    >
      {/* Backdrop */}
      <div className="vb-absolute vb-inset-0 vb-bg-black/50" />

      {/* Modal */}
      <div
        className="vb-relative vb-bg-white vb-rounded-xl vb-shadow-2xl vb-w-full vb-max-w-lg vb-mx-4 vb-max-h-[80vh] vb-flex vb-flex-col"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="vb-flex vb-items-center vb-justify-between vb-px-6 vb-py-4 vb-border-b vb-border-slate-200">
          <h2 className="vb-text-lg vb-font-semibold vb-text-slate-800">
            Attach Figma Frame
          </h2>
          <button
            type="button"
            onClick={onClose}
            className="vb-text-slate-400 hover:vb-text-slate-600 vb-transition-colors vb-p-1 vb-rounded"
            aria-label="Close modal"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <line x1="18" y1="6" x2="6" y2="18" />
              <line x1="6" y1="6" x2="18" y2="18" />
            </svg>
          </button>
        </div>

        {/* Body */}
        <div className="vb-flex-1 vb-overflow-y-auto vb-px-6 vb-py-4 vb-space-y-4">
          {/* URL input */}
          <div className="vb-space-y-2">
            <label
              htmlFor="vb-figma-url"
              className="vb-block vb-text-sm vb-font-medium vb-text-slate-700"
            >
              Figma File URL
            </label>
            <div className="vb-flex vb-gap-2">
              <input
                id="vb-figma-url"
                type="url"
                value={fileUrl}
                onChange={(e) => setFileUrl(e.target.value)}
                placeholder="https://www.figma.com/design/..."
                className="vb-flex-1 vb-rounded-lg vb-border vb-border-slate-300 vb-px-3 vb-py-2 vb-text-sm vb-text-slate-800 vb-placeholder-slate-400 vb-outline-none focus:vb-border-indigo-400 focus:vb-ring-1 focus:vb-ring-indigo-400"
              />
              <button
                type="button"
                onClick={handleFetchFrames}
                disabled={!fileUrl.trim() || isLoadingFrames}
                className="vb-whitespace-nowrap vb-rounded-lg vb-bg-indigo-600 vb-px-4 vb-py-2 vb-text-sm vb-font-medium vb-text-white vb-transition-colors hover:vb-bg-indigo-700 disabled:vb-opacity-50 disabled:vb-cursor-not-allowed"
              >
                {isLoadingFrames ? 'Loading...' : 'Fetch Frames'}
              </button>
            </div>

            {error && (
              <p className="vb-text-sm vb-text-red-600">{error}</p>
            )}
          </div>

          {/* Frame list */}
          {frames.length > 0 && (
            <div className="vb-space-y-2">
              <p className="vb-text-sm vb-font-medium vb-text-slate-700">
                Available Frames
              </p>
              <ul className="vb-space-y-1 vb-max-h-60 vb-overflow-y-auto vb-rounded-lg vb-border vb-border-slate-200 vb-p-2">
                {frames.map((frame) => (
                  <li key={frame.id}>
                    <label className="vb-flex vb-items-center vb-gap-3 vb-rounded-md vb-px-3 vb-py-2 vb-cursor-pointer vb-transition-colors hover:vb-bg-slate-50">
                      <input
                        type="checkbox"
                        checked={selectedFrameIds.has(frame.id)}
                        onChange={() => handleToggleFrame(frame.id)}
                        className="vb-h-4 vb-w-4 vb-rounded vb-border-slate-300 vb-text-indigo-600 focus:vb-ring-indigo-500"
                      />
                      <span className="vb-text-sm vb-text-slate-800">
                        {frame.name}
                        <span className="vb-ml-2 vb-text-xs vb-text-slate-400">
                          {frame.pageName}
                        </span>
                      </span>
                    </label>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="vb-px-6 vb-py-4 vb-border-t vb-border-slate-200 vb-flex vb-justify-end vb-gap-3">
          <button
            type="button"
            onClick={onClose}
            className="vb-rounded-lg vb-border vb-border-slate-300 vb-bg-white vb-px-4 vb-py-2 vb-text-sm vb-font-medium vb-text-slate-700 vb-transition-colors hover:vb-bg-slate-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleAttach}
            disabled={selectedFrameIds.size === 0 || isLoadingContext}
            className="vb-rounded-lg vb-bg-indigo-600 vb-px-4 vb-py-2 vb-text-sm vb-font-medium vb-text-white vb-transition-colors hover:vb-bg-indigo-700 disabled:vb-opacity-50 disabled:vb-cursor-not-allowed"
          >
            {isLoadingContext ? 'Attaching...' : 'Attach Selected'}
          </button>
        </div>
      </div>
    </div>
  );
}
