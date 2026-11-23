@extends('errors.layout')
@section('code', '403')
@section('title', __('You may not open this page'))
@section('message', $exception?->getMessage() && $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : __('Your role does not give access to it. If you need it, ask a manager.'))
