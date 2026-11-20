<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SubmitPublicForm;
use App\Enums\FormStatus;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Form;
use App\Support\Forms\FormDefinitionSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicFormController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $form = $this->findEnabled($token);

        return response()->json([
            'data' => [
                'token' => $form->public_token,
                'name' => $form->name,
                'type' => $form->type?->value,
                'definition' => $form->definition,
            ],
        ]);
    }

    public function submit(Request $request, string $token, SubmitPublicForm $submit): JsonResponse
    {
        $form = $this->findEnabled($token);
        $payload = $request->input('fields', $request->all());
        if (! is_array($payload)) {
            $payload = [];
        }

        $result = $submit->handle(
            $form,
            $payload,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'data' => [
                'ok' => true,
                'submission_id' => $result['submission']->getKey(),
            ],
        ], 201);
    }

    public function embed(string $token): Response
    {
        $form = $this->findEnabled($token);
        $definition = is_array($form->definition) ? $form->definition : [];
        $theme = is_array($definition['theme'] ?? null) ? $definition['theme'] : [];
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
        $accent = e((string) ($theme['accent'] ?? '#2878FF'));
        $background = e((string) ($theme['background'] ?? '#FFFFFF'));
        $text = e((string) ($theme['text'] ?? '#10131C'));
        $headline = e((string) ($definition['headline'] ?? $form->name));
        $subheadline = e((string) ($definition['subheadline'] ?? ''));
        $button = e((string) ($definition['button_label'] ?? 'Subscribe'));
        $apiBase = e(url('/api/v1/public/forms/'.$form->public_token.'/submissions'));

        $fieldHtml = '';
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $id = e((string) ($field['id'] ?? ''));
            $type = (string) ($field['type'] ?? 'text');
            $label = e((string) ($field['label'] ?? ''));
            $required = ($field['required'] ?? false) === true ? 'required' : '';
            $props = is_array($field['props'] ?? null) ? $field['props'] : [];

            if ($type === 'image') {
                $alt = e(is_string($props['alt'] ?? null) && trim($props['alt']) !== ''
                    ? trim($props['alt'])
                    : (string) ($field['label'] ?? 'Image'));
                $src = is_string($props['src'] ?? null) ? trim($props['src']) : '';
                $assetId = is_string($props['asset_id'] ?? null) ? trim($props['asset_id']) : '';
                $imgSrc = '';
                if ($src !== '' && $this->isHttpUrl($src)) {
                    $imgSrc = $src;
                } elseif ($assetId !== '') {
                    $imgSrc = url('/api/v1/public/forms/'.$form->public_token.'/assets/'.$assetId);
                }
                if ($imgSrc !== '') {
                    $fieldHtml .= '<div style="text-align:center;"><img src="'.e($imgSrc).'" alt="'.$alt.'" '
                        .'style="display:block;max-width:100%;height:auto;margin:0 auto;border-radius:8px;"/></div>';
                }

                continue;
            }

            if ($type === 'heading') {
                $fieldHtml .= '<p style="margin:0;font-size:16px;font-weight:700;line-height:1.3;">'.$label.'</p>';

                continue;
            }

            if ($type === 'spacer') {
                $fieldHtml .= '<div style="height:12px;border-top:1px solid rgba(0,0,0,.08);"></div>';

                continue;
            }

            if ($type === 'button' || ! FormDefinitionSchema::isInputType($type)) {
                continue;
            }

            if ($type === 'consent' || $type === 'checkbox') {
                $fieldHtml .= "<label style=\"display:flex;gap:8px;align-items:flex-start;font-size:12px;opacity:.9;\"><input type=\"checkbox\" name=\"{$id}\" {$required}/><span>{$label}</span></label>";
            } else {
                $inputType = $type === 'email' ? 'email' : ($type === 'phone' ? 'tel' : ($type === 'date' ? 'date' : 'text'));
                $fieldHtml .= "<input name=\"{$id}\" type=\"{$inputType}\" placeholder=\"{$label}\" {$required} style=\"height:40px;width:100%;border:1px solid rgba(0,0,0,.1);border-radius:8px;padding:0 12px;\"/>";
            }
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>{$headline}</title>
<style>
  body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f4f6fa;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px;}
  .card{width:100%;max-width:420px;background:{$background};color:{$text};border-radius:16px;padding:28px;box-shadow:0 20px 50px rgba(0,0,0,.12);}
  h1{margin:0;font-size:22px;line-height:1.25;} p{margin:10px 0 0;opacity:.8;font-size:14px;}
  form{margin-top:18px;display:grid;gap:12px;} button{height:42px;border:0;border-radius:8px;background:{$accent};color:#fff;font-weight:600;cursor:pointer;}
  .msg{margin-top:12px;font-size:13px;} .ok{color:#1F9A6A;} .err{color:#D1434B;}
</style></head><body>
<div class="card">
  <h1>{$headline}</h1>
  <p>{$subheadline}</p>
  <form id="f">{$fieldHtml}<button type="submit">{$button}</button></form>
  <div class="msg" id="m"></div>
</div>
<script>
const form=document.getElementById('f'), msg=document.getElementById('m');
form.addEventListener('submit', async (e)=>{
  e.preventDefault(); msg.textContent=''; msg.className='msg';
  const data=new FormData(form); const fields={};
  for (const [k,v] of data.entries()) fields[k]=v;
  form.querySelectorAll('input[type=checkbox]').forEach((el)=>{ fields[el.name]=el.checked; });
  try {
    const res=await fetch('{$apiBase}',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({fields})});
    const json=await res.json().catch(()=>({}));
    if(!res.ok){ throw new Error(json.message||Object.values(json.errors||{}).flat()[0]||'Unable to submit'); }
    msg.textContent='Thanks — you are signed up.'; msg.className='msg ok'; form.reset();
  } catch(err){ msg.textContent=err.message||'Unable to submit'; msg.className='msg err'; }
});
</script></body></html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Stream a store asset referenced by an enabled form (public embed images).
     */
    public function asset(string $token, string $assetId): StreamedResponse
    {
        $form = $this->findEnabled($token);
        if (! $this->formReferencesAsset($form, $assetId)) {
            throw new NotFoundHttpException('Asset not found for this form.');
        }

        $asset = Asset::query()
            ->where('store_id', $form->store_id)
            ->whereKey($assetId)
            ->firstOrFail();

        if (! $asset->fileExists()) {
            throw new NotFoundHttpException('The asset file is no longer available.');
        }

        return Storage::disk($asset->disk ?: 'local')->response(
            $asset->path,
            $asset->original_filename,
            ['Content-Type' => $asset->mime_type ?: 'application/octet-stream'],
        );
    }

    private function findEnabled(string $token): Form
    {
        $form = Form::query()
            ->where('public_token', $token)
            ->where('status', FormStatus::Enabled)
            ->firstOrFail();

        return $form;
    }

    private function formReferencesAsset(Form $form, string $assetId): bool
    {
        $definition = is_array($form->definition) ? $form->definition : [];
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
        foreach ($fields as $field) {
            if (! is_array($field) || ($field['type'] ?? null) !== 'image') {
                continue;
            }
            $props = is_array($field['props'] ?? null) ? $field['props'] : [];
            if (($props['asset_id'] ?? null) === $assetId) {
                return true;
            }
        }

        return false;
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(is_string($scheme) ? strtolower($scheme) : '', ['http', 'https'], true);
    }
}
