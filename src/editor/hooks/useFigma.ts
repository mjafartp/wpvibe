/**
 * useFigma -- Hook for Figma integration state.
 *
 * Provides connection status checks, frame fetching, and context
 * retrieval via the WPVibe REST API. All Figma API calls are
 * server-side (PHP); this hook talks only to the WP REST layer.
 */

import { useState, useCallback } from 'react';
import type { FigmaAttachment, FigmaContext, FigmaFrame } from '@/editor/types';
import {
  saveFigmaConfig,
  testFigmaConnection,
  fetchFigmaFrames,
  fetchFigmaContext,
} from '@/editor/api/wordpress';

export interface UseFigmaReturn {
  /** Whether a Figma token is configured on the server. */
  isConfigured: boolean;
  /** Whether the Figma connection has been verified. */
  isConnected: boolean;
  /** The connected Figma user name (after successful test). */
  userName: string;
  /** Frames fetched from a Figma file. */
  frames: FigmaFrame[];
  /** The Figma file name after frames are fetched. */
  fileName: string;
  /** Whether a Figma operation is in progress. */
  isLoading: boolean;
  /** Error message from the last Figma operation. */
  error: string | null;

  /** Save a Figma Personal Access Token. */
  saveToken: (token: string) => Promise<boolean>;
  /** Test the stored Figma connection. */
  testConnection: () => Promise<boolean>;
  /** Fetch frames from a Figma file URL. */
  loadFrames: (fileUrl: string) => Promise<void>;
  /** Fetch full Figma context for selected frames and return an attachment. */
  getContext: (fileUrl: string, frameIds: string[]) => Promise<FigmaAttachment | null>;
  /** Clear the current error. */
  clearError: () => void;
}

export function useFigma(): UseFigmaReturn {
  const [isConnected, setIsConnected] = useState(false);
  const [userName, setUserName] = useState('');
  const [frames, setFrames] = useState<FigmaFrame[]>([]);
  const [fileName, setFileName] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const isConfigured = !!window.wpvibeData?.hasFigma;

  const saveToken = useCallback(async (token: string): Promise<boolean> => {
    setIsLoading(true);
    setError(null);
    try {
      const result = await saveFigmaConfig(token);
      setIsLoading(false);
      return result.success;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save Figma token.');
      setIsLoading(false);
      return false;
    }
  }, []);

  const testConnection = useCallback(async (): Promise<boolean> => {
    setIsLoading(true);
    setError(null);
    try {
      const result = await testFigmaConnection();
      setIsConnected(result.connected);
      setUserName(result.userName);
      setIsLoading(false);
      return result.connected;
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Figma connection test failed.');
      setIsConnected(false);
      setIsLoading(false);
      return false;
    }
  }, []);

  const loadFrames = useCallback(async (fileUrl: string): Promise<void> => {
    setIsLoading(true);
    setError(null);
    setFrames([]);
    setFileName('');
    try {
      const data = await fetchFigmaFrames(fileUrl);
      setFrames(data.frames);
      setFileName(data.fileName);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to fetch Figma frames.');
    } finally {
      setIsLoading(false);
    }
  }, []);

  const getContext = useCallback(
    async (fileUrl: string, frameIds: string[]): Promise<FigmaAttachment | null> => {
      setIsLoading(true);
      setError(null);
      try {
        const context: FigmaContext = await fetchFigmaContext(fileUrl, frameIds);
        setIsLoading(false);
        return { type: 'figma', context };
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to fetch Figma context.');
        setIsLoading(false);
        return null;
      }
    },
    [],
  );

  const clearError = useCallback(() => setError(null), []);

  return {
    isConfigured,
    isConnected,
    userName,
    frames,
    fileName,
    isLoading,
    error,
    saveToken,
    testConnection,
    loadFrames,
    getContext,
    clearError,
  };
}
