<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SubmitPublicForm;
use App\Enums\FormStatus;
use App\Http\Controllers\Controller;
use App\Models\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
            if ($type === 'consent') {
                $fieldHtml .= "<label style=\"display:flex;gap:8px;align-items:flex-start;font-size:12px;opacity:.9;\"><input type=\"checkbox\" name=\"{$id}\" {$required}/><span>{$label}</span></label>";
            } else {
                $inputType = $type === 'email' ? 'email' : ($type === 'phone' ? 'tel' : 'text');
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

    private function findEnabled(string $token): Form
    {
        $form = Form::query()
            ->where('public_token', $token)
            ->where('status', FormStatus::Enabled)
            ->firstOrFail();

        return $form;
    }
}
