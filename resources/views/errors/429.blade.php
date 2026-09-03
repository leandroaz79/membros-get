@extends('errors::minimal')

@section('title', __('Too Many Requests'))
@section('code', '429')
@section('message', __('Muitas tentativas. Aguarde 1 minuto e tente novamente, ou use o link do e-mail de compra.'))
