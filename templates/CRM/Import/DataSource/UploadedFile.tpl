{$upload_message}
{if array_key_exists('file_name', $form)}
  <table class="form-layout">
    <tr>
      <td class="label">{$form.file_name.label}</td>
      <td>{$form.file_name.html}</td>
    </tr>
    <tr>
      <td class="label">{$form.isFirstRowHeader.label}</td>
      <td>{$form.isFirstRowHeader.html}</td>
    </tr>
  </table>
{/if}
