/**
 * ModelSelector — Dropdown for choosing the AI model.
 *
 * Reads available models and the current selection from the editor store.
 * Grouped by provider type and marks recommended models.
 */

import { useModelSelection } from '@/editor/hooks/useChat';

export function ModelSelector() {
  const { selectedModel, availableModels, setModel } = useModelSelection();

  const handleChange = async (e: React.ChangeEvent<HTMLSelectElement>) => {
    await setModel(e.target.value);
  };

  if (availableModels.length === 0) {
    return (
      <div className="vb-flex vb-items-center vb-text-xs vb-text-slate-400">
        No models available
      </div>
    );
  }

  return (
    <div className="vb-flex vb-items-center vb-gap-2">
      <label
        htmlFor="vb-model-select"
        className="vb-text-xs vb-font-medium vb-text-slate-300 vb-whitespace-nowrap"
      >
        Model:
      </label>
      <select
        id="vb-model-select"
        value={selectedModel}
        onChange={handleChange}
        className="vb-appearance-none vb-bg-slate-700 vb-text-slate-200 vb-text-xs vb-font-medium vb-rounded-lg vb-border vb-border-slate-600 vb-px-3 vb-py-1.5 vb-pr-7 vb-outline-none vb-cursor-pointer vb-transition-colors hover:vb-bg-slate-600 focus:vb-border-indigo-400 focus:vb-ring-1 focus:vb-ring-indigo-400"
        style={{
          backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E")`,
          backgroundRepeat: 'no-repeat',
          backgroundPosition: 'right 8px center',
        }}
      >
        {availableModels.map((model) => (
          <option key={model.id} value={model.id}>
            {model.name}
            {model.recommended ? ' (Recommended)' : ''}
          </option>
        ))}
      </select>
    </div>
  );
}
