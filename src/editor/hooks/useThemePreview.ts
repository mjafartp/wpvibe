/**
 * useThemePreview — Zustand selector hook for preview-related state.
 *
 * Selects only the preview-relevant slices from the editor store so that
 * components using this hook re-render only when preview state changes.
 */

import { useShallow } from 'zustand/react/shallow';
import { useEditorStore } from '@/editor/store/editorStore';
import type { ViewportSize } from '@/editor/store/editorStore';
import type { ThemeVersion } from '@/editor/types';

export interface UseThemePreviewReturn {
  /** URL for the preview iFrame. */
  previewUrl: string;
  /** Current viewport size setting. */
  viewportSize: ViewportSize;
  /** All theme version snapshots for the current session. */
  themeVersions: ThemeVersion[];
  /** Index of the currently displayed version (0-based). */
  currentVersionIndex: number;
  /** Whether the user can navigate backward in version history. */
  canUndo: boolean;
  /** Whether the user can navigate forward in version history. */
  canRedo: boolean;
  /** Whether any theme versions have been generated. */
  hasVersions: boolean;
  /** Whether a theme apply operation is in progress. */
  isApplying: boolean;
  /** Whether a theme export/download is in progress. */
  isExporting: boolean;
  /** Update the preview viewport size. */
  setViewportSize: (size: ViewportSize) => void;
  /** Navigate to the previous theme version. */
  undo: () => void;
  /** Navigate to the next theme version. */
  redo: () => void;
  /** Apply the current theme version to the WordPress site. */
  applyTheme: () => Promise<void>;
  /** Download the current theme version as a ZIP archive. */
  exportTheme: () => Promise<void>;
}

export function useThemePreview(): UseThemePreviewReturn {
  const storeSlice = useEditorStore(
    useShallow((s) => ({
      previewUrl: s.previewUrl,
      viewportSize: s.viewportSize,
      themeVersions: s.themeVersions,
      currentVersionIndex: s.currentVersionIndex,
      isApplying: s.isApplying,
      isExporting: s.isExporting,
      setViewportSize: s.setViewportSize,
      undo: s.undo,
      redo: s.redo,
      applyTheme: s.applyTheme,
      exportTheme: s.exportTheme,
    })),
  );

  const { themeVersions, currentVersionIndex } = storeSlice;

  const canUndo = currentVersionIndex > 0;
  const canRedo = currentVersionIndex < themeVersions.length - 1;
  const hasVersions = themeVersions.length > 0;

  return {
    ...storeSlice,
    canUndo,
    canRedo,
    hasVersions,
    // Wrap so onClick events are not passed as versionId arguments.
    applyTheme: () => storeSlice.applyTheme(),
    exportTheme: () => storeSlice.exportTheme(),
  };
}
