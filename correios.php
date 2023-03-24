<?php

class Correios
{
    private $valor_max = 10000;  // máximo valor declarado, em reais
    private $altura_max = 105;   // todas as medidas em cm
    private $largura_max = 105;
    private $comprimento_max = 105;
    private $altura_min = 2;
    private $largura_min = 11;
    private $comprimento_min = 16;
    private $soma_dim_max = 200;  // medida máxima das somas da altura, largura, comprimento
    private $peso_max = 30;   // em kg
    private $peso_min = 0.300;
    private $peso_limite = 5;   // produto com peso cúbico menor que o limite usa-se o peso da balança, senão usa-se o maior peso entre o da balança e o cúbico
    private $nCdServico = array();
    private $url = '';
    private $quote_data = array();
    private $cep_destino;
    private $cep_origem;
    private $esedex_codigo = '';
    private $esedex_senha = '';
    private $correios = array(
        'Sedex' => '40010',
        '40010' => 'Sedex',
        'Sedex a Cobrar' => '40045',
        '40045' => 'Sedex a Cobrar',
        'PAC' => '41106',
        '41106' => 'PAC',
        'Sedex 10' => '40215',
        '40215' => 'Sedex 10',
        'e-Sedex' => '81019',
        '81019' => 'e-Sedex'
    );
    private $correiosUtilizar = array('PAC', 'Sedex');

    // função responsável pelo retorno à loja dos valores finais dos valores dos fretes
    public function getQuote($address)
    {
        $method_data = array();

        // anulando a sessão do correios
        Yii::app()->user->setState('correios', null);

        // pegando os produtos da sessão
        $produtos = json_decode(Yii::app()->user->getState('carrinho'));

        // obtém só a parte numérica do CEP
        $this->cep_origem = preg_replace("/[^0-9]/", '', $address['cep_origem']);
        $this->cep_destino = preg_replace("/[^0-9]/", '', $address['cep_destino']);

        // ajusta os códigos dos serviços
        foreach ($this->correiosUtilizar as $codigo) {
            $this->nCdServico[] = $this->correios[$codigo];
        }

        // 'empacotando' o carrinho em caixas
        $caixas = $this->organizarEmCaixas($produtos);

        // obtém o frete de cada caixa
        foreach ($caixas as $caixa) {
            $this->setQuoteData($caixa);
        }

        // ajustes finais
        if ($this->quote_data) {

            foreach ($this->quote_data as $codigo => $data) {

                if (is_numeric(trim(textoestatico('correios_adicional')))) {
                    $valor_adicional = trim(textoestatico('correios_adicional'));
                } elseif (preg_match('/%/', trim(textoestatico('correios_adicional')))) {
                    $valor_adicional = $this->quote_data[$codigo]['cost'] * number_format((preg_replace('/%/', '', trim(textoestatico('correios_adicional'))) / 100), 2);
                } else {
                    $valor_adicional = 0;
                }
                // soma o valor adicional ao valor final do frete - não aplicado ao Sedex a Cobrar
                if ($codigo != $this->correios['Sedex a Cobrar']) {
                    $new_cost = $this->quote_data[$codigo]['cost'] + $valor_adicional;
                    // novo custo
                    $this->quote_data[$codigo]['cost'] = $new_cost;
                    // novo texto
                    $this->quote_data[$codigo]['text'] = $new_cost;
                } else {
                    // zera o valor do frete do Sedex a Cobrar para evitar de ser adiconado ao valor do carrinho
                    $this->quote_data[$codigo]['cost'] = 0;
                }
            }
            $method_data = array(
                'code' => 'correios',
                'title' => 'Correios',
                'quote' => $this->quote_data,
                'sort_order' => 0,
                'error' => false
            );

            Yii::app()->user->setState('correios', $method_data);
            if (textoestatico('frete_gratis') > 0.00 && Carrinho::model()->getTotal()->_total > textoestatico('frete_gratis')) {
                $this->quote_data['gratis']['code'] = 'correios.gratis';
                $this->quote_data['gratis']['title'] = 'Nenhum valor será cobrado';
                $this->quote_data['gratis']['cost'] = 0.00;
                $method_data = array(
                    'code' => 'correios',
                    'title' => 'Correios',
                    'quote' => $this->quote_data,
                    'sort_order' => 0,
                    'error' => false
                );
                Yii::app()->user->setState('correios', $method_data);
            }
        }

        return $method_data;
    }

    // obtém os dados dos fretes para os produtos da caixa
    private function setQuoteData($caixa)
    {
        // obtém o valor total da caixa
        $total_caixa = $this->getTotalCaixa($caixa['produtos']);
        $total_caixa = ($total_caixa > $this->valor_max) ? $this->valor_max : $total_caixa;

        list($weight, $height, $width, $length) = $this->ajustarDimensoes($caixa);

        // fazendo a chamada ao site dos Correios e obtendo os dados
        $servicos = $this->getServicos($weight, $total_caixa, $length, $width, $height);

        foreach ($servicos as $servico) {

            // o site dos Correios retornou os dados sem erros.
            $valor_frete_sem_adicionais = $servico['Valor'] - $servico['ValorAvisoRecebimento'] - $servico['ValorMaoPropria'] - $servico['ValorValorDeclarado'];
            if ($servico['Erro'] == 0 && $valor_frete_sem_adicionais > 0) {

                // subtrai do valor do frete as opções desabilitadas nas configurações do módulo - 'declarar valor' é obrigatório para sedex a cobrar
                $cost = (strtolower(substr(textoestatico('correios_declarar_valor'), 0, 1)) == 'n' && $servico['Codigo'] != $this->correios['Sedex a Cobrar']) ? ($servico['Valor'] - $servico['ValorValorDeclarado']) : $servico['Valor'];
                $cost = (strtolower(substr(textoestatico('correios_aviso_recebimento'), 0, 1)) == 'n') ? ($cost - $servico['ValorAvisoRecebimento']) : $cost;
                $cost = (strtolower(substr(textoestatico('correios_mao_propria'), 0, 1)) == 'n') ? ($cost - $servico['ValorMaoPropria']) : $cost;

                // o valor do frete para a caixa atual é somado ao valor total já calculado para outras caixas
                if (isset($this->quote_data[$servico['Codigo']])) {
                    $cost += $this->quote_data[$servico['Codigo']]['cost'];
                }
                // texto a ser exibido para Sedex a Cobrar
                if ($servico['Codigo'] == $this->correios['Sedex a Cobrar']) {
                    $title = sprintf($this->language->get('text_' . $servico['Codigo']), $servico['PrazoEntrega'], $this->currency->format($cost));
                    $text = $this->currency->format($this->tax->calculate($cost, $this->config->get('correios_tax_class_id'), $this->config->get('config_tax')));
                } else {
                    $title = $this->correios[$servico['Codigo']] . '. Entrega: ' . $servico['PrazoEntrega'] . ' dias úteis';
                    $text = $cost;
                }

                $this->quote_data[$servico['Codigo']] = array(
                    'code' => 'correios.' . $servico['Codigo'],
                    'title' => $title,
                    'cost' => $cost,
                    'tax_class_id' => 0,
                    'text' => $text
                );
            }
            // grava no log de erros do OpenCart a mensagem de erro retornado pelos Correios
            else {
                Yii::log('Correios: [' . $this->correios[$servico['Codigo']] . ']' . $servico['MsgErro'], CLogger::LEVEL_INFO);
            }
        }
    }

    // prepara a url de chamada ao site dos Correios
    private function setUrl($peso, $valor, $comp, $larg, $alt)
    {
        $url = "http://ws.correios.com.br/calculador/CalcPrecoPrazo.aspx?";
        $url .= "nCdEmpresa=" . $this->esedex_codigo;
        $url .= "&sDsSenha=" . $this->esedex_senha;
        $url .= "&sCepOrigem=%s";
        $url .= "&sCepDestino=%s";
        $url .= "&nVlPeso=%s";
        $url .= "&nCdFormato=1";
        $url .= "&nVlComprimento=%s";
        $url .= "&nVlLargura=%s";
        $url .= "&nVlAltura=%s";
        $url .= "&sCdMaoPropria=s";
        $url .= "&nVlValorDeclarado=%s";
        $url .= "&sCdAvisoRecebimento=s";
        $url .= "&nCdServico=" . implode(',', $this->nCdServico);
        $url .= "&nVlDiametro=0";
        $url .= "&StrRetorno=xml";

        $this->url = sprintf($url, $this->cep_origem, $this->cep_destino, $peso, $comp, $larg, $alt, $valor);
    }

    // conecta ao sites dos Correios e obtém o arquivo XML com os dados do frete
    private function getXML($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($ch);

        curl_close($ch);

        $result = str_replace('&amp;lt;sup&amp;gt;&amp;amp;reg;&amp;lt;/sup&amp;gt;', '', $result);
        $result = str_replace('&amp;lt;sup&amp;gt;&amp;amp;trade;&amp;lt;/sup&amp;gt;', '', $result);
        $result = str_replace('**', '', $result);
        $result = str_replace("\r\n", '', $result);
        $result = str_replace('\"', '"', $result);

        return $result;
    }

    // faz a chamada e lê os dados no arquivo XML retornado pelos Correios
    public function getServicos($peso, $valor, $comp, $larg, $alt)
    {
        $dados = array();

        // troca o separador decimal de ponto para vírgula nos dados a serem enviados para os Correios
        $peso = str_replace('.', ',', $peso);

        $valor = str_replace('.', ',', $valor);
        $valor = number_format((float) $valor, 2, ',', '.');

        $comp = str_replace('.', ',', $comp);
        $larg = str_replace('.', ',', $larg);
        $alt = str_replace('.', ',', $alt);

        // ajusta a url de chamada
        $this->setUrl($peso, $valor, $comp, $larg, $alt);

        // faz a chamada e retorna o xml com os dados
        $xml = $this->getXML($this->url);

        // lendo o xml
        if ($xml) {
            $dom = new DOMDocument('1.0', 'ISO-8859-1');
            $dom->loadXml($xml);

            $servicos = $dom->getElementsByTagName('cServico');

            if ($servicos) {

                // obtendo o prazo adicional a ser somado com o dos Correios
                $prazo_adicional = (is_numeric(trim(textoestatico('correios_prazo_adicional')))) ? trim(textoestatico('correios_prazo_adicional')) : 0;

                foreach ($servicos as $servico) {
                    $codigo = $servico->getElementsByTagName('Codigo')->item(0)->nodeValue;
                    // Sedex 10 não tem prazo adicional
                    $prazo = ($codigo == $this->correios['Sedex 10']) ? 0 : $prazo_adicional;

                    $dados[$codigo] = array(
                        "Codigo" => $codigo,
                        "Valor" => str_replace(',', '.', $servico->getElementsByTagName('Valor')->item(0)->nodeValue),
                        "PrazoEntrega" => ($servico->getElementsByTagName('PrazoEntrega')->item(0)->nodeValue + $prazo),
                        "Erro" => $servico->getElementsByTagName('Erro')->item(0)->nodeValue,
                        "MsgErro" => $servico->getElementsByTagName('MsgErro')->item(0)->nodeValue,
                        "ValorMaoPropria" => (isset($servico->getElementsByTagName('ValorMaoPropria')->item(0)->nodeValue)) ? str_replace(',', '.', $servico->getElementsByTagName('ValorMaoPropria')->item(0)->nodeValue) : 0,
                        "ValorAvisoRecebimento" => (isset($servico->getElementsByTagName('ValorAvisoRecebimento')->item(0)->nodeValue)) ? str_replace(',', '.', $servico->getElementsByTagName('ValorAvisoRecebimento')->item(0)->nodeValue) : 0,
                        "ValorValorDeclarado" => (isset($servico->getElementsByTagName('ValorValorDeclarado')->item(0)->nodeValue)) ? str_replace(',', '.', $servico->getElementsByTagName('ValorValorDeclarado')->item(0)->nodeValue) : 0
                    );
                }
            }
        }

        return $dados;
    }

    // retorna a dimensão em centímetros
    private function getDimensaoEmCm($unidade, $dimensao)
    {
        if ($unidade == 'mm' && is_numeric($dimensao)) {
            return $dimensao / 10;
        }

        return $dimensao;
    }

    // retorna o peso em quilogramas
    private function getPesoEmKg($unidade, $peso)
    {
        if ($unidade == 'g' && is_numeric($peso)) {
            return ($peso / 1000);
        }

        return $peso;
    }

    // seleciona o maior peso entre o da balança e o cúbico com base na regra dos Correios
    private function getMaiorPeso($pesoNormal, $pesoCubico)
    {
        if ($pesoCubico <= $this->peso_limite) {
            return $pesoNormal;
        } else {
            return ($pesoNormal >= $pesoCubico) ? $pesoNormal : $pesoCubico;
        }
    }

    // pré-validação das dimensões e peso do produto
    private function validar($produto)
    {
        if (!is_numeric($produto->height) || !is_numeric($produto->width) || !is_numeric($produto->length) || !is_numeric($produto->weight)) {
            Yii::log('Correios: [Valores da caixa]' . $produto->name, CLogger::LEVEL_INFO);

            return false;
        }

        $altura = $produto->height;
        $largura = $produto->width;
        $comprimento = $produto->length;
        $peso = $produto->weight;

        if ($altura > $this->altura_max || $largura > $this->largura_max || $comprimento > $this->comprimento_max) {
            Yii::log('Correios: [Limite da caixa]' . sprintf($this->comprimento_max, $this->largura_max, $this->altura_max, $produto->name, $comprimento, $largura, $altura), CLogger::LEVEL_INFO);

            return false;
        }

        $soma_dim = $altura + $largura + $comprimento;
        if ($soma_dim > $this->soma_dim_max) {
            Yii::log('Correios: [Limite da caixa]' . sprintf($this->comprimento_max, $this->largura_max, $this->altura_max, $produto->name, $comprimento, $largura, $altura), CLogger::LEVEL_INFO);

            return false;
        }

        if ($peso > $this->peso_max) {
            Yii::log('Correios: [Limite da caixa]' . sprintf($this->peso_max, $produto->name, $peso), CLogger::LEVEL_INFO);

            return false;
        }

        return true;
    }

    // 'empacota' os produtos do carrinho em caixas com dimensões e peso dentro dos limites definidos pelos Correios
    // algoritmo desenvolvido por: Thalles Cardoso <thallescard@gmail.com>
    private function organizarEmCaixas($produtos)
    {
        $tipo = !Yii::app()->user->isGuest ? Clientetipo::model()->findByPk(Yii::app()->user->codclientetipo) : 0;

        $caixas = array();
        $total = 0;

        foreach ($produtos as $prod) {

            $produto = Produto::model()->findByPk($prod->codproduto);

            // quantidade do produto
            $quantidade = $prod->quantidade;

            $prod_copy = $prod;
            // adicionar o nome do produto
            $prod_copy->name = $produto->titulo;

            // adicionar o valor do  produto
            $prod_copy->price = $produto->valor;

            // adicionar o valor total
            $prod_copy->total = $prod_copy->price * $quantidade;

            // muda-se a quantidade do produto para incrementá-la em cada caixa
            $prod_copy->quantidade = 1;

            // valor bruto do produto
            $prod_copy->peso = $produto->categoria->peso * $quantidade;

            // todas as dimensões da caixa serão em cm e kg
            $prod_copy->width = $this->getDimensaoEmCm('cm', $produto->categoria->largura);
            $prod_copy->height = $this->getDimensaoEmCm('cm', $produto->categoria->altura);
            $prod_copy->length = $this->getDimensaoEmCm('cm', $produto->categoria->comprimento);

            // O peso do produto não é unitário como a dimensão. É multiplicado pela quantidade. Se quisermos o peso unitário, teremos que dividir pela quantidade.
            $prod_copy->weight = $this->getPesoEmKg('kg', $prod_copy->peso) / $quantidade;

            $cx_num = 0;

            for ($i = 1; $i <= $quantidade; $i++) {

                // valida as dimensões do produto com as dos Correios
                if ($this->validar($prod_copy)) {

                    // cria-se a caixa caso ela não exista.
                    isset($caixas[$cx_num]['weight']) ? true : $caixas[$cx_num]['weight'] = 0;
                    isset($caixas[$cx_num]['height']) ? true : $caixas[$cx_num]['height'] = 0;
                    isset($caixas[$cx_num]['width']) ? true : $caixas[$cx_num]['width'] = 0;
                    isset($caixas[$cx_num]['length']) ? true : $caixas[$cx_num]['length'] = 0;

                    $new_width = $caixas[$cx_num]['width'] + $prod_copy->width;
                    $new_height = $caixas[$cx_num]['height'] + $prod_copy->height;
                    $new_length = $caixas[$cx_num]['length'] + $prod_copy->length;
                    $new_weight = $caixas[$cx_num]['weight'] + $prod_copy->weight;

                    $cabe_do_lado = ($new_width < $this->largura_max) && ($new_width + $caixas[$cx_num]['height'] + $caixas[$cx_num]['length'] < $this->soma_dim_max);

                    $cabe_no_fundo = ($new_length < $this->comprimento_max) && ($new_length + $caixas[$cx_num]['width'] + $caixas[$cx_num]['height'] < $this->soma_dim_max);

                    $cabe_em_cima = ($new_height < $this->altura_max) && ($new_height + $caixas[$cx_num]['width'] + $caixas[$cx_num]['length'] < $this->soma_dim_max);

                    $peso_dentro_limite = ($new_weight <= $this->peso_max) ? true : false;

                    // o produto cabe na caixa
                    if (($cabe_do_lado || $cabe_no_fundo || $cabe_em_cima) && $peso_dentro_limite) {

                        // já existe o mesmo produto na caixa, assim incrementa-se a sua quantidade

                        if (isset($caixas[$cx_num]['produtos'][$prod_copy->codproduto])) {
                            $caixas[$cx_num]['produtos'][$prod_copy->codproduto]->quantidade++;
                        }
                        // adiciona o novo produto
                        else {
                            $caixas[$cx_num]['produtos'][$prod_copy->codproduto] = $prod_copy;
                        }

                        // aumenta-se o peso da caixa
                        $caixas[$cx_num]['weight'] += $prod_copy->weight;

                        // ajusta-se as dimensões da nova caixa
                        if ($cabe_do_lado) {
                            $caixas[$cx_num]['width'] += $prod_copy->width;

                            // a caixa vai ficar com a altura do maior produto que estiver nela
                            $caixas[$cx_num]['height'] = max($caixas[$cx_num]['height'], $prod_copy->height);

                            // a caixa vai ficar com o comprimento do maior produto que estiver nela
                            $caixas[$cx_num]['length'] = max($caixas[$cx_num]['length'], $prod_copy->length);
                        } elseif ($cabe_no_fundo) {
                            $caixas[$cx_num]['length'] += $prod_copy->length;

                            // a caixa vai ficar com a altura do maior produto que estiver nela
                            $caixas[$cx_num]['height'] = max($caixas[$cx_num]['height'], $prod_copy->height);

                            // a caixa vai ficar com a largura do maior produto que estiver nela
                            $caixas[$cx_num]['width'] = max($caixas[$cx_num]['width'], $prod_copy->width);
                        } elseif ($cabe_em_cima) {
                            $caixas[$cx_num]['height'] += $prod_copy->height;

                            //a caixa vai ficar com a altura do maior produto que estiver nela
                            $caixas[$cx_num]['width'] = max($caixas[$cx_num]['width'], $prod_copy->width);

                            //a caixa vai ficar com a largura do maior produto que estiver nela
                            $caixas[$cx_num]['length'] = max($caixas[$cx_num]['length'], $prod_copy->length);
                        }
                    }
                    // tenta adicionar o produto que não coube em uma nova caixa
                    else {
                        $cx_num++;
                        $i--;
                    }
                }
                // produto não tem as dimensões/peso válidos então abandona sem calcular o frete.
                else {
                    $caixas = array();
                    break 2;  // sai dos dois foreach
                }
            }
        }

        return $caixas;
    }

    // retorna o valor total dos prodtos na caixa
    private function getTotalCaixa($products)
    {
        $total = 0;

        foreach ($products as $product) {
            //$total += $this->currency->format($this->tax->calculate($product['total'], $product['tax_class_id'], $this->config->get('config_tax')), '', '', false);
            $total += $product->total;
        }

        return $total;
    }

    private function ajustarDimensoes($caixa)
    {
        // a altura não pode ser maior que o comprimento, assim inverte-se as dimensões
        $height = $caixa['height'];
        $width = $caixa['width'];
        $length = $caixa['length'];
        $weight = $caixa['weight'];

        // se dimensões menores que a permitida, ajusta para o padrão
        if ($height < $this->altura_min) {
            $height = $this->altura_min;
        }
        if ($width < $this->largura_min) {
            $width = $this->largura_min;
        }
        if ($length < $this->comprimento_min) {
            $length = $this->comprimento_min;
        }
        if ($weight < $this->peso_min) {
            $weight = $this->peso_min;
        }
        if ($height > $length) {
            $temp = $height;
            $height = $length;
            $length = $temp;
        }

        return array($weight, $height, $width, $length);
    }

}