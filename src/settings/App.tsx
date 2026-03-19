import { useState, useEffect } from 'react';
import type { Model, KeyType } from '@/editor/types';
import { saveFigmaConfig, testFigmaConnection, disconnectFigma } from '@/editor/api/wordpress';
import './settings.css';

type Tab = 'api' | 'editor' | 'figma';

interface ModelsResponse {
  models: Model[];
  currentModel: string;
  keyType: KeyType;
}

type CssFramework = 'tailwind' | 'bootstrap' | 'vanilla';

const CSS_FRAMEWORKS: { id: CssFramework; name: string; description: string }[] = [
  {
    id: 'tailwind',
    name: 'Tailwind CSS',
    description: 'Utility-first CSS framework. Classes applied directly in HTML. Fast prototyping, highly customizable.',
  },
  {
    id: 'bootstrap',
    name: 'Bootstrap 5',
    description: 'Component-based CSS framework. Pre-built components, grid system, responsive utilities.',
  },
  {
    id: 'vanilla',
    name: 'Vanilla CSS',
    description: 'Hand-written custom CSS. Full control, no dependencies, uses modern CSS features.',
  },
];

function EditorPreferences() {
  const { restUrl, nonce } = window.wpvibeData || {};
  const [cssFramework, setCssFramework] = useState<CssFramework>(
    (window.wpvibeData?.cssFramework as CssFramework) || 'tailwind',
  );
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const handleSave = async (framework: CssFramework) => {
    setCssFramework(framework);
    setSaving(true);
    setMessage(null);
    try {
      await fetch(`${restUrl}save-settings`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({ css_framework: framework }),
      });
      setMessage({ type: 'success', text: 'CSS framework preference saved.' });
      setTimeout(() => setMessage(null), 3000);
    } catch {
      setMessage({ type: 'error', text: 'Failed to save preference.' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="vb-panel">
      <h3>Editor Preferences</h3>

      <h4 style={{ marginTop: '20px', marginBottom: '4px' }}>CSS Framework</h4>
      <p className="description" style={{ marginBottom: '16px' }}>
        Choose the default CSS framework the AI will use when generating themes.
        You can always override this per-conversation by telling the AI to use a different framework.
      </p>

      <div style={{ display: 'flex', flexDirection: 'column', gap: '10px', maxWidth: '520px' }}>
        {CSS_FRAMEWORKS.map((fw) => {
          const isSelected = cssFramework === fw.id;
          return (
            <button
              key={fw.id}
              type="button"
              onClick={() => handleSave(fw.id)}
              disabled={saving}
              style={{
                display: 'flex',
                alignItems: 'flex-start',
                gap: '12px',
                padding: '14px 16px',
                borderRadius: '8px',
                border: isSelected ? '2px solid #4f46e5' : '2px solid #e2e8f0',
                background: isSelected ? '#eef2ff' : '#ffffff',
                cursor: saving ? 'wait' : 'pointer',
                textAlign: 'left',
                transition: 'all 0.15s',
              }}
            >
              {/* Radio circle */}
              <span style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                width: '20px',
                height: '20px',
                borderRadius: '50%',
                border: isSelected ? '2px solid #4f46e5' : '2px solid #cbd5e1',
                flexShrink: 0,
                marginTop: '1px',
              }}>
                {isSelected && (
                  <span style={{
                    width: '10px',
                    height: '10px',
                    borderRadius: '50%',
                    background: '#4f46e5',
                  }} />
                )}
              </span>
              <div>
                <div style={{
                  fontSize: '14px',
                  fontWeight: 600,
                  color: isSelected ? '#312e81' : '#1e293b',
                  marginBottom: '2px',
                }}>
                  {fw.name}
                  {fw.id === 'tailwind' && (
                    <span style={{
                      fontSize: '10px',
                      fontWeight: 500,
                      color: '#4f46e5',
                      background: '#e0e7ff',
                      padding: '1px 6px',
                      borderRadius: '4px',
                      marginLeft: '8px',
                      verticalAlign: 'middle',
                    }}>
                      Default
                    </span>
                  )}
                </div>
                <div style={{ fontSize: '12px', color: '#64748b', lineHeight: 1.4 }}>
                  {fw.description}
                </div>
              </div>
            </button>
          );
        })}
      </div>

      {message && (
        <div style={{
          padding: '8px 12px',
          borderRadius: '6px',
          marginTop: '16px',
          maxWidth: '520px',
          backgroundColor: message.type === 'success' ? '#f0fdf4' : '#fef2f2',
          border: `1px solid ${message.type === 'success' ? '#bbf7d0' : '#fecaca'}`,
          fontSize: '13px',
          color: message.type === 'success' ? '#166534' : '#991b1b',
        }}>
          {message.text}
        </div>
      )}
    </div>
  );
}

function FigmaSettings() {
  const [token, setToken] = useState('');
  const [showToken, setShowToken] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isTesting, setIsTesting] = useState(false);
  const [isDisconnecting, setIsDisconnecting] = useState(false);
  const [isChangingKey, setIsChangingKey] = useState(false);
  const [status, setStatus] = useState<{ type: 'success' | 'error'; message: string } | null>(null);
  const [connectionInfo, setConnectionInfo] = useState<{ connected: boolean; userName: string } | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    testFigmaConnection()
      .then((result) => setConnectionInfo(result))
      .catch(() => setConnectionInfo(null))
      .finally(() => setIsLoading(false));
  }, []);

  const handleSave = async () => {
    if (!token.trim()) return;
    setIsSaving(true);
    setStatus(null);
    try {
      const result = await saveFigmaConfig(token.trim());
      if (result.success) {
        setStatus({ type: 'success', message: 'Figma token saved successfully.' });
        setToken('');
        setIsChangingKey(false);
        const conn = await testFigmaConnection();
        setConnectionInfo(conn);
      }
    } catch (err) {
      setStatus({ type: 'error', message: err instanceof Error ? err.message : 'Failed to save token.' });
    } finally {
      setIsSaving(false);
    }
  };

  const handleTest = async () => {
    setIsTesting(true);
    setStatus(null);
    try {
      const result = await testFigmaConnection();
      setConnectionInfo(result);
      if (result.connected) {
        setStatus({ type: 'success', message: `Connected as ${result.userName}` });
      } else {
        setStatus({ type: 'error', message: 'Not connected. Please save a valid token.' });
      }
    } catch (err) {
      setStatus({ type: 'error', message: err instanceof Error ? err.message : 'Connection test failed.' });
    } finally {
      setIsTesting(false);
    }
  };

  const handleDisconnect = async () => {
    if (!confirm('Are you sure you want to disconnect Figma? You can reconnect anytime.')) return;
    setIsDisconnecting(true);
    setStatus(null);
    try {
      await disconnectFigma();
      setConnectionInfo({ connected: false, userName: '' });
      setIsChangingKey(false);
      setToken('');
      setStatus({ type: 'success', message: 'Figma disconnected.' });
    } catch (err) {
      setStatus({ type: 'error', message: err instanceof Error ? err.message : 'Failed to disconnect.' });
    } finally {
      setIsDisconnecting(false);
    }
  };

  const isConnected = connectionInfo?.connected === true;

  return (
    <div className="vb-panel">
      <h3>Figma Integration</h3>
      <p className="description" style={{ marginBottom: '16px' }}>
        Connect your Figma account to attach design frames directly to chat messages.
        You'll need a <a href="https://www.figma.com/developers/api#access-tokens" target="_blank" rel="noopener noreferrer">Personal Access Token</a> from Figma.
      </p>

      {isLoading ? (
        <p style={{ color: '#64748b', fontSize: '13px' }}>Checking connection...</p>
      ) : isConnected && !isChangingKey ? (
        /* ---- Connected state ---- */
        <div>
          <div style={{
            padding: '16px',
            borderRadius: '8px',
            marginBottom: '16px',
            backgroundColor: '#f0fdf4',
            border: '1px solid #bbf7d0',
          }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                <span style={{
                  display: 'inline-block',
                  width: '10px',
                  height: '10px',
                  borderRadius: '50%',
                  backgroundColor: '#22c55e',
                  flexShrink: 0,
                }} />
                <div>
                  <div style={{ fontSize: '14px', fontWeight: 600, color: '#166534' }}>
                    Connected to Figma
                  </div>
                  {connectionInfo?.userName && (
                    <div style={{ fontSize: '12px', color: '#15803d', marginTop: '2px' }}>
                      Signed in as <strong>{connectionInfo.userName}</strong>
                    </div>
                  )}
                </div>
              </div>
            </div>
          </div>

          {/* Status message */}
          {status && (
            <div style={{
              padding: '8px 12px',
              borderRadius: '6px',
              marginBottom: '16px',
              backgroundColor: status.type === 'success' ? '#f0fdf4' : '#fef2f2',
              border: `1px solid ${status.type === 'success' ? '#bbf7d0' : '#fecaca'}`,
              fontSize: '13px',
              color: status.type === 'success' ? '#166534' : '#991b1b',
            }}>
              {status.message}
            </div>
          )}

          <p className="submit" style={{ margin: 0 }}>
            <button
              type="button"
              className="button"
              onClick={() => {
                setIsChangingKey(true);
                setStatus(null);
              }}
            >
              Change Token
            </button>
            {' '}
            <button
              type="button"
              className="button"
              onClick={handleTest}
              disabled={isTesting}
            >
              {isTesting ? 'Testing...' : 'Test Connection'}
            </button>
            {' '}
            <button
              type="button"
              className="button"
              onClick={handleDisconnect}
              disabled={isDisconnecting}
              style={{ color: '#dc2626' }}
            >
              {isDisconnecting ? 'Disconnecting...' : 'Disconnect'}
            </button>
          </p>
        </div>
      ) : (
        /* ---- Disconnected / Change key state ---- */
        <div>
          {isChangingKey && isConnected && (
            <div style={{
              padding: '8px 12px',
              borderRadius: '6px',
              marginBottom: '16px',
              backgroundColor: '#f0fdf4',
              border: '1px solid #bbf7d0',
              fontSize: '13px',
              color: '#166534',
              display: 'flex',
              alignItems: 'center',
              gap: '8px',
            }}>
              <span style={{
                display: 'inline-block',
                width: '8px',
                height: '8px',
                borderRadius: '50%',
                backgroundColor: '#22c55e',
              }} />
              Currently connected as {connectionInfo?.userName}
            </div>
          )}

          {!isConnected && !isChangingKey && (
            <div style={{
              padding: '8px 12px',
              borderRadius: '6px',
              marginBottom: '16px',
              backgroundColor: '#fef2f2',
              border: '1px solid #fecaca',
              fontSize: '13px',
              color: '#991b1b',
            }}>
              Not connected to Figma
            </div>
          )}

          <table className="form-table">
            <tbody>
              <tr>
                <th scope="row">
                  <label htmlFor="figma-token">
                    {isChangingKey ? 'New Access Token' : 'Personal Access Token'}
                  </label>
                </th>
                <td>
                  <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                    <input
                      id="figma-token"
                      type={showToken ? 'text' : 'password'}
                      value={token}
                      onChange={(e) => setToken(e.target.value)}
                      placeholder="figd_xxxxxxxxxxxxxxxxxxxxxxxx"
                      className="regular-text"
                      style={{ flex: 1 }}
                    />
                    <button
                      type="button"
                      className="button"
                      onClick={() => setShowToken(!showToken)}
                    >
                      {showToken ? 'Hide' : 'Show'}
                    </button>
                  </div>
                  <p className="description">
                    Generate a token at Figma &rarr; Settings &rarr; Personal Access Tokens.
                  </p>
                </td>
              </tr>
            </tbody>
          </table>

          {/* Status message */}
          {status && (
            <div style={{
              padding: '8px 12px',
              borderRadius: '6px',
              marginBottom: '16px',
              backgroundColor: status.type === 'success' ? '#f0fdf4' : '#fef2f2',
              border: `1px solid ${status.type === 'success' ? '#bbf7d0' : '#fecaca'}`,
              fontSize: '13px',
              color: status.type === 'success' ? '#166534' : '#991b1b',
            }}>
              {status.message}
            </div>
          )}

          <p className="submit">
            <button
              type="button"
              className="button button-primary"
              onClick={handleSave}
              disabled={isSaving || !token.trim()}
            >
              {isSaving ? 'Saving...' : 'Save Token'}
            </button>
            {' '}
            {isChangingKey && (
              <button
                type="button"
                className="button"
                onClick={() => {
                  setIsChangingKey(false);
                  setToken('');
                  setStatus(null);
                }}
              >
                Cancel
              </button>
            )}
          </p>
        </div>
      )}
    </div>
  );
}

export function SettingsApp() {
  const [activeTab, setActiveTab] = useState<Tab>('api');
  const [models, setModels] = useState<Model[]>([]);
  const [selectedModel, setSelectedModel] = useState('');
  const [keyType, setKeyType] = useState<string>(window.wpvibeData?.keyType || '');
  const [hasKey, setHasKey] = useState(window.wpvibeData?.hasKey || false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState('');

  // API key editing state
  const [isEditingKey, setIsEditingKey] = useState(false);
  const [newKey, setNewKey] = useState('');
  const [showKey, setShowKey] = useState(false);
  const [keyValidating, setKeyValidating] = useState(false);
  const [keyMessage, setKeyMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  const { restUrl, nonce } = window.wpvibeData || {};

  useEffect(() => {
    if (hasKey) {
      loadModels();
    }
  }, [hasKey]);

  async function loadModels() {
    try {
      const res = await fetch(`${restUrl}models`, {
        headers: { 'X-WP-Nonce': nonce },
      });
      const data: ModelsResponse = await res.json();
      setModels(data.models);
      setKeyType(data.keyType || '');

      // If the saved model isn't in the available list (e.g. switched providers),
      // auto-select the first recommended or first available model.
      const modelIds = data.models.map((m: Model) => m.id);
      let resolved = data.currentModel || '';
      if (!resolved || !modelIds.includes(resolved)) {
        const rec = data.models.find((m: Model) => m.recommended);
        resolved = rec?.id ?? data.models[0]?.id ?? '';
        if (resolved) {
          saveModel(resolved);
        }
      }
      setSelectedModel(resolved);
    } catch {
      // Silently fail — models will be empty.
    }
  }

  async function saveModel(modelId: string) {
    setSaving(true);
    setMessage('');
    try {
      await fetch(`${restUrl}save-settings`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({ selected_model: modelId }),
      });
      setSelectedModel(modelId);
      setMessage('Settings saved.');
    } catch {
      setMessage('Failed to save settings.');
    }
    setSaving(false);
  }

  async function validateAndSaveKey() {
    if (!newKey.trim()) return;
    setKeyValidating(true);
    setKeyMessage(null);
    try {
      const res = await fetch(`${restUrl}validate-key`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': nonce,
        },
        body: JSON.stringify({ key: newKey.trim() }),
      });
      const data = await res.json();
      if (data.valid) {
        setKeyMessage({ type: 'success', text: `Key saved — ${data.message || 'Connected successfully.'}` });
        setHasKey(true);
        setKeyType(data.keyType || '');
        setNewKey('');
        setIsEditingKey(false);
        // Reload models with new key
        loadModels();
      } else {
        setKeyMessage({ type: 'error', text: data.message || 'Invalid API key.' });
      }
    } catch {
      setKeyMessage({ type: 'error', text: 'Failed to validate key. Please try again.' });
    } finally {
      setKeyValidating(false);
    }
  }

  const keyTypeLabels: Record<string, string> = {
    wpvibe_service: 'WPVibe Service Key',
    claude_api: 'Anthropic Claude API',
    claude_oauth: 'Claude OAuth Token',
    openai_codex: 'OpenAI / Codex',
  };

  return (
    <div className="vb-settings">
      {/* Tab navigation */}
      <div className="vb-tabs">
        <button
          className={`vb-tab ${activeTab === 'api' ? 'vb-tab-active' : ''}`}
          onClick={() => setActiveTab('api')}
        >
          API Configuration
        </button>
        <button
          className={`vb-tab ${activeTab === 'editor' ? 'vb-tab-active' : ''}`}
          onClick={() => setActiveTab('editor')}
        >
          Editor Preferences
        </button>
        <button
          className={`vb-tab ${activeTab === 'figma' ? 'vb-tab-active' : ''}`}
          onClick={() => setActiveTab('figma')}
        >
          Figma Config
        </button>
      </div>

      {/* Tab content */}
      <div className="vb-tab-content">
        {activeTab === 'api' && (
          <div className="vb-panel">
            <h3>API Key Status</h3>
            {hasKey && !isEditingKey ? (
              <>
                <div className="vb-key-status vb-key-status-active" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <span className="vb-status-dot" />
                    Connected — {keyTypeLabels[keyType] || keyType}
                  </div>
                  <button
                    type="button"
                    className="button button-small"
                    onClick={() => {
                      setIsEditingKey(true);
                      setKeyMessage(null);
                    }}
                    style={{ marginLeft: '12px' }}
                  >
                    Change Key
                  </button>
                </div>
              </>
            ) : (
              <div>
                {hasKey && isEditingKey && (
                  <div className="vb-key-status vb-key-status-active" style={{ marginBottom: '16px', display: 'flex', alignItems: 'center', gap: '8px' }}>
                    <span className="vb-status-dot" />
                    Current: {keyTypeLabels[keyType] || keyType}
                  </div>
                )}

                {!hasKey && !isEditingKey && (
                  <div className="vb-key-status vb-key-status-inactive" style={{ marginBottom: '16px' }}>
                    <span className="vb-status-dot" />
                    No API key configured.
                  </div>
                )}

                <table className="form-table">
                  <tbody>
                    <tr>
                      <th scope="row">
                        <label htmlFor="api-key-input">{hasKey ? 'New API Key' : 'API Key'}</label>
                      </th>
                      <td>
                        <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                          <input
                            id="api-key-input"
                            type={showKey ? 'text' : 'password'}
                            value={newKey}
                            onChange={(e) => setNewKey(e.target.value)}
                            placeholder="sk-ant-..., sk-..., or vb_live_..."
                            className="regular-text"
                            style={{ flex: 1 }}
                          />
                          <button
                            type="button"
                            className="button"
                            onClick={() => setShowKey(!showKey)}
                          >
                            {showKey ? 'Hide' : 'Show'}
                          </button>
                        </div>
                        <p className="description">
                          Supports: WP Vibe key (vb_live_...), Anthropic (sk-ant-...), OpenAI (sk-...), or Claude OAuth token.
                        </p>
                      </td>
                    </tr>
                  </tbody>
                </table>

                {keyMessage && (
                  <div style={{
                    padding: '8px 12px',
                    borderRadius: '6px',
                    marginBottom: '16px',
                    backgroundColor: keyMessage.type === 'success' ? '#f0fdf4' : '#fef2f2',
                    border: `1px solid ${keyMessage.type === 'success' ? '#bbf7d0' : '#fecaca'}`,
                    fontSize: '13px',
                    color: keyMessage.type === 'success' ? '#166534' : '#991b1b',
                  }}>
                    {keyMessage.text}
                  </div>
                )}

                <p className="submit">
                  <button
                    type="button"
                    className="button button-primary"
                    onClick={validateAndSaveKey}
                    disabled={keyValidating || !newKey.trim()}
                  >
                    {keyValidating ? 'Validating...' : 'Validate & Save Key'}
                  </button>
                  {' '}
                  {isEditingKey && (
                    <button
                      type="button"
                      className="button"
                      onClick={() => {
                        setIsEditingKey(false);
                        setNewKey('');
                        setKeyMessage(null);
                      }}
                    >
                      Cancel
                    </button>
                  )}
                </p>
              </div>
            )}

            {models.length > 0 && (
              <>
                <h3 style={{ marginTop: 24 }}>AI Model</h3>
                <select
                  className="vb-model-select"
                  value={selectedModel}
                  onChange={(e) => saveModel(e.target.value)}
                  disabled={saving}
                >
                  {models.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.name}
                      {m.recommended ? ' (Recommended)' : ''} — {m.description}
                    </option>
                  ))}
                </select>
              </>
            )}

            {message && <p className="vb-save-message">{message}</p>}
          </div>
        )}

        {activeTab === 'editor' && (
          <EditorPreferences />
        )}

        {activeTab === 'figma' && (
          <FigmaSettings />
        )}
      </div>
    </div>
  );
}
