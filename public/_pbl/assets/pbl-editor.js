/* PBL4 — editor de texto rico dos campos "Texto formatado" (ADR 0020). Arquivo próprio, sem inline, dirigido por atributos data-*.
   Carregado só nas telas que têm <textarea data-pbl-editor="html">. Se o TinyMCE não carregar ou falhar, o textarea continua
   sendo um campo de HTML comum. O servidor continua a autoridade: o HTML é limpo por HtmlSeguro ao salvar (ADR 0012 §9);
   o que se configura aqui só evita lixo (Word, Google Docs) chegar ao servidor. Nada vai para fora do site: sem CDN, sem nuvem. */
(function () {
  'use strict';

  if (!window.tinymce) {
    return;
  }

  /* Elementos e estilos que o servidor aceita (HtmlSeguro); o resto o editor já nem produz. */
  var ELEMENTOS_PERMITIDOS =
    'p[dir|style|title|lang],br,hr,div[dir|style|title|lang],span[style|title|lang],' +
    'h1[dir|style],h2[dir|style],h3[dir|style],h4[dir|style],h5[dir|style],h6[dir|style],' +
    'strong/b,em/i,u,s,small,sub,sup,mark,blockquote[style],q,cite,code,pre[style],kbd,abbr[title],' +
    'ul,ol[start],li[style],dl,dt,dd,' +
    'table[border|cellpadding|cellspacing|style],thead,tbody,tfoot,tr[style],caption[style],colgroup[span],col[span|style],' +
    'th[colspan|rowspan|scope|style],td[colspan|rowspan|style],' +
    'figure,figcaption,a[href|title],img[src|alt|title|width|height]';

  /* Colar do Word: só estas marcações sobrevivem (tudo mso-*, <o:p>, classes Mso* e estilos caem). */
  var ELEMENTOS_DO_WORD =
    'p,br,h1,h2,h3,h4,h5,h6,strong/b,em/i,u,s,sub,sup,ul,ol,li,table,thead,tbody,tfoot,tr,th,td,caption,a[href],img[src|alt],blockquote';

  document.querySelectorAll('textarea[data-pbl-editor="html"]').forEach(function (area) {
    var base = area.getAttribute('data-pbl-editor-base');
    if (!base) {
      return;
    }
    /* `required` num campo escondido pelo editor impede o envio sem mostrar o erro; o servidor valida do mesmo jeito. */
    area.removeAttribute('required');
    area.classList.remove('font-monospace');

    window.tinymce.init({
      target: area,
      base_url: base,
      suffix: '.min',
      language: 'pt_BR',
      language_url: base + '/langs/pt_BR.js',

      /* Sem promoção, sem telemetria, sem menu de nuvem; interface simples. */
      promotion: false,
      branding: false,
      menubar: false,
      statusbar: true,
      elementpath: false,
      resize: true,
      min_height: 360,
      browser_spellcheck: true,
      contextmenu: false,
      help_accessibility: false,

      plugins: 'lists advlist link table image code autolink',
      toolbar:
        'undo redo | blocks fontsize | bold italic underline | alignleft aligncenter alignright alignjustify | ' +
        'bullist numlist outdent indent | link table image | removeformat | code',
      toolbar_mode: 'sliding',
      block_formats: 'Parágrafo=p; Título 2=h2; Título 3=h3; Título 4=h4; Citação=blockquote; Texto de código=pre',
      font_size_formats: '12px 14px 16px 18px 24px 32px',
      /* Só estas propriedades de estilo existem no conteúdo (o servidor aceita as mesmas). */
      valid_styles: {
        '*': 'text-align,font-size',
        table: 'width,height,border-collapse',
        tr: 'height',
        th: 'text-align,font-size,width,height',
        td: 'text-align,font-size,width,height',
        col: 'width'
      },
      valid_elements: ELEMENTOS_PERMITIDOS,
      invalid_elements: 'script,style,iframe,object,embed,form,input,button,select,textarea,svg,math,meta,link,xml',
      entity_encoding: 'raw',
      convert_urls: false,
      relative_urls: false,
      remove_script_host: false,
      allow_script_urls: false,
      allow_html_data_urls: false,

      /* Links e imagens: por endereço, sem envio de arquivo (imagens do site entram pelo campo de imagem do conteúdo). */
      link_title: true,
      link_default_protocol: 'https',
      link_assume_external_targets: 'https',
      link_context_toolbar: false,
      target_list: false,
      rel_list: false,
      image_description: true,
      image_title: false,
      image_advtab: false,
      image_dimensions: true,
      image_caption: false,
      automatic_uploads: false,
      file_picker_types: '',

      /* Colar: do Word e de HTML de qualquer lugar chega limpo; imagem colada (data:/blob:) não é aceita. */
      paste_data_images: false,
      paste_remove_styles_if_webkit: true,
      paste_webkit_styles: 'none',
      paste_merge_formats: true,
      paste_tab_spaces: 2,
      paste_word_valid_elements: ELEMENTOS_DO_WORD,
      paste_as_text: false,
      smart_paste: true,

      content_style: 'body { font-family: system-ui, sans-serif; font-size: 16px; } img { max-width: 100%; height: auto; } table { border-collapse: collapse; } td, th { border: 1px solid #adb5bd; padding: 4px; }',

      setup: function (editor) {
        /* Garante que o textarea receba o HTML antes de qualquer envio do formulário. */
        editor.on('init', function () {
          var form = area.form;
          if (form) {
            form.addEventListener('submit', function () {
              editor.save();
            });
          }
        });
      }
    });
  });
})();
